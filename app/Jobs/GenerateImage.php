<?php

namespace App\Jobs;

use App\Budgets\Budgets;
use App\Images\ImageException;
use App\Images\ImageFiles;
use App\Images\ImageGenerator;
use App\Models\Generation;
use App\Models\Output;
use App\Requests\Changes;
use App\Requests\Runs;
use App\Settings\Settings;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Makes one image of a generation: one provider call (tried again when
 * the provider is busy), the file and its thumbnail on the disk, the cost
 * recorded. A refused or impossible image is written on the generation
 * and the rest of the run carries on.
 */
class GenerateImage implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public Generation $generation, public int $position)
    {
        $this->timeout = ((int) app(Settings::class)->get('images.timeout_seconds') + 30) * ((int) app(Settings::class)->get('generation.retries') + 1);
    }

    public function handle(Runs $runs, ImageGenerator $generator, ImageFiles $files, Budgets $budgets, Settings $settings): void
    {
        $generation = $this->generation->fresh();
        if (! $generation || $generation->status === Generation::FAILED || $this->batch()?->cancelled()) {
            return;
        }

        $generation->update(['status' => Generation::RUNNING, 'started_at' => $generation->started_at ?? now()]);
        $request = $generation->request;
        $model = $generation->model();

        try {
            if (! $model) {
                throw new ImageException("The model {$generation->image_model} isn't in the list any more.");
            }

            // A change is turned into its instruction by the planner first, from the original image.
            if ($generation->isChange() && ! $generation->change) {
                try {
                    app(Changes::class)->plan($generation);
                    $generation->refresh();
                } catch (RuntimeException $e) {
                    throw new ImageException($e->getMessage());
                }
            }

            $result = null;
            $tries = (int) $settings->get('generation.retries') + 1;
            for ($try = 1; $try <= $tries; $try++) {
                try {
                    $result = $generator->generate($model, $runs->imageRequest($generation), $request->brand);
                    break;
                } catch (ImageException $e) {
                    if (! $e->retryable || $try === $tries) {
                        throw $e;
                    }
                    Sleep::for(10 * $try)->seconds();
                }
            }
        } catch (ImageException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->failed_($generation, $message, stopAll: $e instanceof ImageException && ! $e->retryable && ! $e->blocked);

            return;
        }

        $image = $result->images[0];
        [$path, $thumb] = $files->store("requests/{$request->id}/outputs/{$generation->id}", (string) $this->position, $image);

        $output = null;
        DB::transaction(function () use ($generation, $request, $result, $image, $path, $thumb, &$output) {
            $output = Output::create([
                'request_id' => $request->id, 'generation_id' => $generation->id, 'source_id' => $generation->source_output_id, 'ratio' => $result->aspectRatio ?: $generation->ratio, 'kind' => Output::IMAGE,
                'position' => $this->position, 'path' => $path, 'thumb_path' => $thumb, 'mime' => $image->mime, 'width' => $image->width, 'height' => $image->height,
                'size' => strlen($image->data), 'served_by' => $result->servedBy, 'cost' => $result->cost,
            ]);
            $generation->increment('done');
            $generation->increment('cost', $result->cost);
            $this->settle($generation->fresh());
        });

        $budgets->record($model->provider, 'image', $result->cost, request: $request, note: "{$generation->ratio} image {$this->position}");

        // Finished ads are made from each image as it lands, within the same batch, in every
        // language whose words are ready (the copywriter adds the others when they land).
        if ($output && in_array($request->output, ['ad', 'both'], true)) {
            $jobs = array_map(fn (string $language) => new ComposeAds($output, $language), $runs->adLanguagesReady($request));
            $this->batch() ? $this->batch()->add($jobs) : collect($jobs)->each(fn ($job) => dispatch($job));
        }

        // And every image is reviewed.
        if ($output) {
            $review = new ReviewOutput($output);
            $this->batch() ? $this->batch()->add([$review]) : dispatch($review);
        }
    }

    /**
     * A failed image: counted, the reason kept; a reason that would fail
     * every image (no connection, a bad key) stops the whole shape.
     */
    private function failed_(Generation $generation, string $message, bool $stopAll): void
    {
        $generation->increment('failed');
        $generation->update(['error' => $message]);

        if ($stopAll) {
            $generation->update(['status' => Generation::FAILED, 'finished_at' => now()]);

            return;
        }

        $this->settle($generation->fresh());
    }

    private function settle(Generation $generation): void
    {
        if ($generation->isFinished() && $generation->status !== Generation::FAILED) {
            $generation->update(['status' => $generation->done > 0 ? Generation::DONE : Generation::FAILED, 'finished_at' => now()]);
        }
    }
}
