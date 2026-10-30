<?php

namespace App\Jobs;

use App\Models\CreativeRequest;
use App\Notices\Notices;
use App\Requests\Planner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the planner on a request in the background. One at a time per request.
 */
class PlanRequest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 660;

    public function __construct(public CreativeRequest $request) {}

    public function uniqueId(): string
    {
        return (string) $this->request->id;
    }

    public function handle(Planner $planner, ?Notices $notices = null): void
    {
        $notices ??= app(Notices::class);
        $this->request->refresh();

        if ($this->request->status !== CreativeRequest::PLANNING) {
            return;
        }

        try {
            $planner->plan($this->request);
        } catch (Throwable $e) {
            $this->request->update(['status' => CreativeRequest::FAILED, 'error' => $e->getMessage()]);
            report($e);
        }

        // The planner stopped for the owner: a question, a plan to confirm, or a failure.
        $notices->needsYou($this->request->fresh());
    }

    public function failed(?Throwable $e): void
    {
        if ($request = $this->request->fresh()) {
            $request->update(['status' => CreativeRequest::FAILED, 'error' => $e?->getMessage() ?: 'The planner stopped before it finished.']);
            app(Notices::class)->needsYou($request);
        }
    }
}
