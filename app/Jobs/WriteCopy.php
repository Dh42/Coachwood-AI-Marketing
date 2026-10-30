<?php

namespace App\Jobs;

use App\Copy\CopyWriter;
use App\Models\Copy;
use App\Models\CreativeRequest;
use App\Models\Output;
use App\Models\Placement;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes the copy for one placement in one language, or the words for the
 * finished ads in one language. Inside the request's batch. When the ad
 * words land, the finished ads in that language are made for every image
 * already here (later images make their own).
 */
class WriteCopy implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public CreativeRequest $request, public ?int $placementId, public string $language) {}

    public function handle(CopyWriter $writer): void
    {
        $request = $this->request->fresh();
        if (! $request || $request->status === CreativeRequest::CANCELLED || $this->batch()?->cancelled()) {
            return;
        }

        try {
            if ($this->placementId === null) {
                $writer->ad($request, $this->language);
                $this->composeExisting($request);
            } else {
                $placement = Placement::find($this->placementId);
                if ($placement) {
                    $writer->placement($request, $placement, $this->language);
                }
            }
        } catch (Throwable $e) {
            Copy::updateOrCreate(['request_id' => $request->id, 'placement_id' => $this->placementId, 'language' => $this->language, 'kind' => $this->placementId === null ? Copy::AD : Copy::PLACEMENT],
                ['versions' => [], 'status' => Copy::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            report($e);
        }
    }

    private function composeExisting(CreativeRequest $request): void
    {
        if (! in_array($request->output, ['ad', 'both'], true)) {
            return;
        }

        $jobs = Output::where('request_id', $request->id)->where('kind', Output::IMAGE)->get()
            ->map(fn (Output $image) => new ComposeAds($image, $this->language))->all();

        if ($jobs !== []) {
            $this->batch() ? $this->batch()->add($jobs) : collect($jobs)->each(fn ($job) => dispatch($job));
        }
    }
}
