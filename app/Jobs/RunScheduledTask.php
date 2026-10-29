<?php

namespace App\Jobs;

use App\Models\ScheduledTaskRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

/**
 * A scheduled task someone ran now from the Scheduled tasks page: runs the
 * command and keeps what it printed on its run.
 */
class RunScheduledTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $runId)
    {
        $this->onConnection(config('queue.long'));
    }

    public function handle(): void
    {
        $run = ScheduledTaskRun::find($this->runId);

        if (! $run || $run->status !== ScheduledTaskRun::QUEUED) {
            return;
        }

        $run->update(['status' => ScheduledTaskRun::RUNNING, 'started_at' => now()]);
        $start = microtime(true);

        try {
            $code = Artisan::call($run->command);
            $output = Artisan::output();
        } catch (Throwable $e) {
            $this->finish($run, $start, 1, Artisan::output(), $e->getMessage());

            throw $e;
        }

        $this->finish($run, $start, $code, $output, $code === 0 ? null : "Exited with code {$code}.");
    }

    public function failed(?Throwable $e): void
    {
        ScheduledTaskRun::whereKey($this->runId)->whereIn('status', [ScheduledTaskRun::QUEUED, ScheduledTaskRun::RUNNING])
            ->update(['status' => ScheduledTaskRun::FAILED, 'finished_at' => now(), 'error' => Str::limit($e?->getMessage() ?: 'The run stopped before it finished.', 1000)]);
    }

    private function finish(ScheduledTaskRun $run, float $start, int $code, string $output, ?string $error): void
    {
        $run->update([
            'status' => $code === 0 ? ScheduledTaskRun::OK : ScheduledTaskRun::FAILED,
            'finished_at' => now(),
            'runtime_ms' => (int) round((microtime(true) - $start) * 1000),
            'exit_code' => $code,
            'output' => Str::limit(trim($output), 20000) ?: null,
            'error' => $error ? Str::limit($error, 1000) : null,
        ]);
    }
}
