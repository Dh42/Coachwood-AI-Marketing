<?php

namespace App\Console\Commands;

use App\Scheduler\Scheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Keeps a watch on the scheduler alive in the queue (WatchScheduler), so
 * admins hear when the scheduler itself stops.
 */
#[Signature('scheduler:watch')]
#[Description('Keep the queue watching that the scheduled tasks are still running')]
class WatchScheduler extends Command
{
    public function handle(Scheduler $scheduler): int
    {
        $this->info($scheduler->watch() ? 'Started watching.' : 'Already being watched.');

        return self::SUCCESS;
    }
}
