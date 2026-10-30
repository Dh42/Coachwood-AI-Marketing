<?php

namespace App\Requests;

use App\Budgets\Budgets;
use App\Copy\CopyWriter;
use App\Images\ImageGenerator;
use App\Images\ImageRequest;
use App\Images\ReferenceImage;
use App\Jobs\GenerateImage;
use App\Jobs\WriteCopy;
use App\Models\Copy;
use App\Models\CreativeRequest;
use App\Models\Generation;
use App\Models\ImageModel;
use App\Models\Placement;
use App\Models\RequestReference;
use App\Notices\Notices;
use App\Plugins\Contracts\ImageProvider;
use App\Plugins\PluginRegistry;
use App\Prompts\Prompts;
use App\Prompts\PromptType;
use App\Prompts\ResolvedPrompt;
use App\Review\Shortlist;
use App\Settings\Settings;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Running a confirmed request: one generation per shape, one job per
 * image, and the request marked done when the last job has finished.
 */
class Runs
{
    public function __construct(private PluginRegistry $plugins, private ImageGenerator $generator, private Settings $settings, private Shortlist $shortlist, private Prompts $prompts) {}

    /**
     * Makes the generations from the plan and queues a job per image.
     */
    public function start(CreativeRequest $request): void
    {
        $plan = $request->plan ?? [];
        $size = (string) ($plan['size'] ?? $this->settings->get('images.default_size', $request->brand_id));
        $jobs = [];
        $rules = $this->prompts->resolve(PromptType::Image, $request->brand, $request->user);

        DB::transaction(function () use ($request, $size, $rules, &$jobs) {
            $request->generations()->delete();

            foreach ($request->targets()->get()->groupBy('ratio')->sortKeys() as $ratio => $targets) {
                $target = $targets->first();
                $model = ImageModel::findByKey($target->image_model);
                $plugin = $model ? $this->plugins->plugin($model->provider) : null;

                $generation = $request->generations()->create([
                    'ratio' => $ratio,
                    'image_model' => $target->image_model,
                    'prompt' => $this->prompt($request, $ratio, $rules),
                    ...$rules->ids(),
                    'size' => $size,
                    'quality' => $model && $plugin instanceof ImageProvider ? $plugin->defaultQuality($model, $request->brand) : null,
                    'count' => $target->count,
                ]);

                for ($i = 1; $i <= $target->count; $i++) {
                    $jobs[] = new GenerateImage($generation, $i);
                }
            }

            $request->copies()->delete();
            foreach ($this->copyJobs($request) as $job) {
                $jobs[] = $job;
            }

            $request->update(['status' => CreativeRequest::RUNNING, 'actual_cost' => 0, 'finished_at' => null, 'error' => null]);
        });

        $batch = Bus::batch($jobs)
            ->name("Request {$request->id}")
            ->allowFailures()
            ->finally(fn (Batch $batch) => app(self::class)->finish(CreativeRequest::find($request->id)))
            ->dispatch();

        $request->update(['batch_id' => $batch->id]);
    }

    /**
     * The copy jobs for a run: each placement that takes copy, in each
     * language; and the words on the finished ads in each language that
     * isn't English (the plan's words are English).
     *
     * @return list<WriteCopy>
     */
    public function copyJobs(CreativeRequest $request): array
    {
        $jobs = [];
        $languages = $request->languages ?: ['en'];

        if ($request->copy !== 'none') {
            $placements = Placement::whereIn('id', $request->targets()->pluck('placement_id'))->get()
                ->filter(fn (Placement $p) => CopyWriter::limitsFor($request, $p) !== []);
            foreach ($placements as $placement) {
                foreach ($languages as $language) {
                    $jobs[] = new WriteCopy($request, $placement->id, $language);
                }
            }
        }

        if (in_array($request->output, ['ad', 'both'], true)) {
            foreach (array_diff($languages, ['en']) as $language) {
                $jobs[] = new WriteCopy($request, null, $language);
            }
        }

        return $jobs;
    }

    /**
     * The languages a finished ad can be composed in right now: English
     * from the plan, others once their words are written.
     *
     * @return list<string>
     */
    public function adLanguagesReady(CreativeRequest $request): array
    {
        $written = Copy::where('request_id', $request->id)->where('kind', Copy::AD)->where('status', Copy::DONE)->pluck('language')->all();

        return array_values(array_filter($request->languages ?: ['en'], fn ($l) => $l === 'en' || in_array($l, $written, true)));
    }

    /**
     * The prompt for one shape: the plan's scene, its note for that shape,
     * and the brand's standing rules for images (the no-text rule and
     * whatever the team added), with the person's own additions.
     */
    public function prompt(CreativeRequest $request, string $ratio, ?ResolvedPrompt $rules = null): string
    {
        $rules ??= $this->prompts->resolve(PromptType::Image, $request->brand, $request->user);
        $parts = [trim((string) $request->plan('image_prompt'))];
        if ($note = trim((string) $request->plan("ratio_notes.{$ratio}"))) {
            $parts[] = $note;
        }
        $parts[] = trim($rules->text);

        return implode("\n\n", array_filter($parts));
    }

    /**
     * The request to send for one image of a generation.
     */
    public function imageRequest(Generation $generation): ImageRequest
    {
        if ($generation->isChange()) {
            return app(Changes::class)->imageRequest($generation);
        }

        $request = $generation->request;
        $model = $generation->model();
        $references = [];

        // The look wanted goes with the prompt where the model takes it; product shots are for the composer.
        if ($model && $model->edits) {
            foreach ($request->references->where('kind', RequestReference::STYLE)->take($model->max_references) as $reference) {
                $references[] = ReferenceImage::fromDisk($reference->path, 'the look and mood wanted, not the subject');
            }
        }

        return new ImageRequest($generation->prompt, $generation->ratio, $generation->size, $generation->quality, $references, 1);
    }

    /**
     * Called when the last job has finished: the request is done, or
     * failed when nothing came back.
     */
    public function finish(?CreativeRequest $request): void
    {
        if (! $request || $request->status !== CreativeRequest::RUNNING) {
            return;
        }

        $generations = $request->generations()->get();
        foreach ($generations as $generation) {
            if (! in_array($generation->status, [Generation::DONE, Generation::FAILED], true)) {
                $generation->update(['status' => $generation->done > 0 ? Generation::DONE : Generation::FAILED, 'finished_at' => now(), 'error' => $generation->error ?: ($generation->done > 0 ? null : 'Stopped before it finished.')]);
            }
        }

        // The team sees the best of what passed; the rest is kept as rejected with its reason.
        $this->shortlist->make($request);

        $made = $request->outputs()->count();
        $delivered = $request->outputs()->where('status', Shortlist::SHORTLISTED)->count();
        $errors = $generations->pluck('error')->filter()->unique()->implode(' ');

        $request->update([
            'status' => $made > 0 ? CreativeRequest::DONE : CreativeRequest::FAILED,
            'actual_cost' => round((float) $generations->sum('cost') + (float) $request->spend()->where('kind', '!=', 'image')->sum('amount'), 5),
            'error' => $made > 0 ? null : ($errors ?: 'No image came back.'),
            'finished_at' => now(),
        ]);

        activity('requests')->performedOn($request)->event($made > 0 ? 'request_done' : 'request_failed')->withProperties(['outputs' => $made, 'cost' => $request->actual_cost])
            ->log($made > 0 ? "Finished \"{$request->title}\": {$delivered} of {$made} outputs delivered, for \$".Budgets::money((float) $request->actual_cost) : "Failed \"{$request->title}\": {$request->error}");

        app(Notices::class)->finished($request);
    }
}
