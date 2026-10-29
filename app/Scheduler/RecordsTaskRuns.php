<?php

namespace App\Scheduler;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Writes each scheduled run to scheduled_task_runs from the scheduler's own
 * events, and keeps the heartbeat the Scheduled tasks page and the watch
 * job read. A task run in the background finishes from its own process
 * (schedule:finish), so its row stays "running" until then.
 */
class RecordsTaskRuns
{
    public function __construct(private Scheduler $scheduler) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, [$this, 'starting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'finished']);
        $events->listen(ScheduledBackgroundTaskFinished::class, [$this, 'backgroundFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'failed']);
    }

    public function starting(ScheduledTaskStarting $e): void
    {
        $this->scheduler->beat();

        if (! ($command = $this->command($e->task))) {
            return;
        }

        ScheduledTaskRun::create(['command' => $command, 'source' => ScheduledTaskRun::SCHEDULE, 'status' => ScheduledTaskRun::RUNNING, 'started_at' => now()]);

        // Old rows go once an hour, from whichever run comes first.
        if (Cache::add('scheduler:pruned', true, now()->addHour())) {
            $this->scheduler->prune();
        }
    }

    public function finished(ScheduledTaskFinished $e): void
    {
        if ($e->task->runInBackground) {
            return; // Still running: backgroundFinished() closes it.
        }

        $this->close($e->task, (int) round($e->runtime * 1000));
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $e): void
    {
        $this->close($e->task);
    }

    public function failed(ScheduledTaskFailed $e): void
    {
        if (! ($command = $this->command($e->task))) {
            return;
        }

        $run = ScheduledTaskRun::where(['command' => $command, 'source' => ScheduledTaskRun::SCHEDULE])->latest('id')->first();

        $run?->update([
            'status' => ScheduledTaskRun::FAILED,
            'error' => Str::limit($e->exception->getMessage(), 1000),
            'finished_at' => $run->finished_at ?? now(),
            'runtime_ms' => $run->runtime_ms ?? (int) $run->started_at->diffInMilliseconds(now()),
            'exit_code' => $run->exit_code ?? $e->task->exitCode,
        ]);
    }

    private function close(Event $task, ?int $runtimeMs = null): void
    {
        if (! ($command = $this->command($task))) {
            return;
        }

        $run = ScheduledTaskRun::where(['command' => $command, 'source' => ScheduledTaskRun::SCHEDULE, 'status' => ScheduledTaskRun::RUNNING])->latest('id')->first();

        $run?->update([
            'status' => (int) $task->exitCode === 0 ? ScheduledTaskRun::OK : ScheduledTaskRun::FAILED,
            'finished_at' => now(),
            'runtime_ms' => $runtimeMs ?? (int) $run->started_at->diffInMilliseconds(now()),
            'exit_code' => $task->exitCode,
            'error' => (int) $task->exitCode === 0 ? null : "Exited with code {$task->exitCode}.",
        ]);
    }

    private function command(Event $task): ?string
    {
        return $task instanceof CallbackEvent ? null : $this->scheduler->commandName($task);
    }
}
