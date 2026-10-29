<?php

namespace App\Jobs;

use App\Scheduler\Scheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Watches the scheduler from the queue, which runs on its own: checks
 * whether it has gone quiet, then queues itself again for later. One chain
 * at a time: the token in the cache says which, and a chain that finds
 * another's token stops. The scheduler starts a chain when none is alive.
 */
class WatchScheduler implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $token) {}

    public function handle(Scheduler $scheduler): void
    {
        if (Cache::get(Scheduler::WATCH) !== $this->token) {
            return;
        }

        $scheduler->checkStalled();

        // A queue that runs jobs on the spot (sync) would never stop.
        if (config('queue.default') === 'sync') {
            return;
        }

        $minutes = $scheduler->stalledMinutes();
        Cache::put(Scheduler::WATCH, $this->token, now()->addMinutes($minutes * 3));
        self::dispatch($this->token)->delay(now()->addMinutes($minutes));
    }
}
