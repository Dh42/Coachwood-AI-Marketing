<?php

namespace App\Scheduler;

use App\Auth\Role;
use App\Jobs\RunScheduledTask;
use App\Jobs\WatchScheduler;
use App\Models\RoleAssignment;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Notifications\SchedulerStalled;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * The tasks on the scheduler's timer, what each last did, whether the
 * scheduler itself is still running, and running a task now. The runs are
 * recorded by RecordsTaskRuns; the scheduler's health is watched from the
 * queue (WatchScheduler), because the scheduler can't report its own death.
 */
class Scheduler
{
    /** When the scheduler last started a task. */
    public const HEARTBEAT = 'scheduler:last_run_at';

    /** The token of the watch chain in the queue, while one is alive. */
    public const WATCH = 'scheduler:watch';

    /** Set once admins have been emailed about the current stall. */
    public const STALL_EMAILED = 'scheduler:stall_emailed';

    public function __construct(private Settings $settings) {}

    /**
     * Every scheduled artisan command, the most frequent first, with its last run.
     *
     * @return list<ScheduledTask>
     */
    public function tasks(): array
    {
        $tasks = [];

        foreach ($this->schedule()->events() as $event) {
            if ($event instanceof CallbackEvent || ! ($command = $this->commandName($event))) {
                continue;
            }

            $tasks[$command] = new ScheduledTask(
                command: $command,
                description: $event->description ?: $this->describe($command),
                every: Frequency::describe($event->expression, $event->timezone ?: config('app.timezone'), $this->settings->get('platform.timezone')),
                expression: $event->expression,
                background: $event->runInBackground,
            );
        }

        $latest = ScheduledTaskRun::whereIn('id', ScheduledTaskRun::selectRaw('max(id)')->whereIn('command', array_keys($tasks))->groupBy('command'))
            ->with('user')->get()->keyBy('command');

        $tasks = array_map(fn (ScheduledTask $t) => $t->withLastRun($latest->get($t->command)), array_values($tasks));
        usort($tasks, fn (ScheduledTask $a, ScheduledTask $b) => [Frequency::minutes($a->expression), $a->description] <=> [Frequency::minutes($b->expression), $b->description]);

        return $tasks;
    }

    public function has(string $command): bool
    {
        foreach ($this->schedule()->events() as $event) {
            if (! $event instanceof CallbackEvent && $this->commandName($event) === $command) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs a task now, in the queue, and records it as that person's run.
     *
     * @throws InvalidArgumentException when the command isn't a scheduled task
     */
    public function run(string $command, User $by): ScheduledTaskRun
    {
        if (! $this->has($command)) {
            throw new InvalidArgumentException("\"{$command}\" isn't one of the scheduled tasks.");
        }

        $run = ScheduledTaskRun::create([
            'command' => $command, 'source' => ScheduledTaskRun::MANUAL, 'user_id' => $by->id,
            'status' => ScheduledTaskRun::QUEUED, 'started_at' => now(),
        ]);

        activity('scheduler')->causedBy($by)->event('task_run')->withProperties(['command' => $command])
            ->log("Ran \"{$this->describe($command)}\" now");

        RunScheduledTask::dispatch($run->id);

        return $run;
    }

    /**
     * When the scheduler last started a task; null when it never has.
     */
    public function lastRunAt(): ?CarbonImmutable
    {
        if ($at = Cache::get(self::HEARTBEAT)) {
            return CarbonImmutable::parse($at);
        }

        $at = ScheduledTaskRun::where('source', ScheduledTaskRun::SCHEDULE)->max('started_at');

        return $at ? CarbonImmutable::parse($at) : null;
    }

    public function beat(): void
    {
        Cache::put(self::HEARTBEAT, now()->toIso8601String(), now()->addDays(30));
    }

    /**
     * Whether the scheduler has gone quiet: it has run before, but not for
     * longer than scheduler.stalled_minutes. Never true before its first run.
     */
    public function stalled(): bool
    {
        $last = $this->lastRunAt();

        return $last !== null && $last->addMinutes($this->stalledMinutes())->isPast();
    }

    public function stalledMinutes(): int
    {
        return max(1, (int) $this->settings->get('scheduler.stalled_minutes'));
    }

    /**
     * Emails the platform admins once per stall; forgets that once the
     * scheduler is running again, so the next stall is reported too.
     */
    public function checkStalled(): void
    {
        if (! $this->stalled()) {
            Cache::forget(self::STALL_EMAILED);

            return;
        }

        if (! Cache::add(self::STALL_EMAILED, true, now()->addDay())) {
            return;
        }

        $admins = User::whereIn('id', RoleAssignment::where('role', Role::Admin)->where('brand_id', RoleAssignment::PLATFORM)->pluck('user_id'))
            ->get()->reject(fn (User $u) => $u->isDisabled());

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new SchedulerStalled($this->lastRunAt()));
        }
    }

    /**
     * Starts the watch chain in the queue if none is alive. The scheduler
     * calls this every few minutes (scheduler:watch); the chain carries on
     * by itself after the scheduler stops, which is when it matters.
     */
    public function watch(): bool
    {
        $token = bin2hex(random_bytes(8));

        if (! Cache::add(self::WATCH, $token, now()->addMinutes($this->stalledMinutes() * 3))) {
            return false;
        }

        WatchScheduler::dispatch($token);

        return true;
    }

    /**
     * Removes runs older than scheduler.keep_days.
     */
    public function prune(): int
    {
        return ScheduledTaskRun::where('started_at', '<', now()->subDays(max(1, (int) $this->settings->get('scheduler.keep_days'))))->delete();
    }

    /**
     * The command's own description: the plain words the task is listed under.
     */
    public function describe(string $command): string
    {
        $commands = app(Kernel::class)->all();

        return isset($commands[$command]) ? (string) $commands[$command]->getDescription() : $command;
    }

    /**
     * "alerts:check" from the event's full command line ('php' 'artisan' alerts:check).
     */
    public function commandName(Event $event): ?string
    {
        $command = trim(str_replace([Artisan::phpBinary(), Artisan::artisanBinary()], ['php', 'artisan'], (string) $event->command));

        return preg_replace('/^php artisan\s+/', '', $command) ?: null;
    }

    private function schedule(): Schedule
    {
        return app(Schedule::class);
    }
}
