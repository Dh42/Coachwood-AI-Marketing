<?php

namespace App\Jobs;

use App\Composer\ComposeException;
use App\Composer\Composer;
use App\Models\CreativeRequest;
use App\Models\Output;
use App\Models\Placement;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Makes the finished ads from one clean image: one per placement of its
 * shape, in one language. Runs inside the request's batch
 * so the request is done only when its ads are.
 */
class ComposeAds implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Output $image, public string $language = 'en') {}

    public function handle(Composer $composer): void
    {
        $image = $this->image->fresh();
        if (! $image || $this->batch()?->cancelled()) {
            return;
        }

        $request = $image->request;
        if (! in_array($request->output, ['ad', 'both'], true) || $request->status === CreativeRequest::CANCELLED) {
            return;
        }

        $placements = Placement::whereIn('id', $request->targets()->where('ratio', $image->generation->ratio)->pluck('placement_id'))->get();

        foreach ($placements as $placement) {
            try {
                $ad = $composer->compose($image, $placement, null, $this->language);
                $review = new ReviewOutput($ad);
                $this->batch() ? $this->batch()->add([$review]) : dispatch($review);
            } catch (ComposeException $e) {
                $image->generation->update(['compose_error' => $e->getMessage()]);
            } catch (Throwable $e) {
                $image->generation->update(['compose_error' => 'The composer failed: '.mb_substr($e->getMessage(), 0, 300)]);
                report($e);
            }
        }
    }
}
