<?php

namespace App\Jobs;

use App\Models\CreativeRequest;
use App\Models\Output;
use App\Models\Review;
use App\Review\Reviewer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reviews one output inside the request's batch. An output that already
 * failed a check (size, file size, safe zone) isn't sent for review: the
 * shortlist rejects it on the check alone.
 */
class ReviewOutput implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public Output $output) {}

    public function handle(Reviewer $reviewer): void
    {
        $output = $this->output->fresh();
        if (! $output || $this->batch()?->cancelled() || $output->request->status === CreativeRequest::CANCELLED) {
            return;
        }

        if (collect($output->checks ?? [])->contains(fn ($c) => in_array($c['check'], ['size', 'file_size', 'safe_zone'], true) && ! $c['ok'])) {
            return;
        }

        try {
            $reviewer->review($output);
        } catch (Throwable $e) {
            Review::updateOrCreate(['output_id' => $output->id], ['scores' => [], 'hard_fails' => [], 'status' => Review::FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)]);
            report($e);
        }
    }
}
