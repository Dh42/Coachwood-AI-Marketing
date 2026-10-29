<?php

use App\Auth\Role;
use App\Jobs\WatchScheduler;
use App\Livewire\Scheduler\ScheduledTasks;
use App\Livewire\Settings\SettingsForm;
use App\Models\ScheduledTaskRun;
use App\Notifications\SchedulerStalled;
use App\Scheduler\Frequency;
use App\Scheduler\Scheduler;
use App\Settings\SettingScope;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** The schedule's own event for a command, as the scheduler would fire it. */
function scheduledEvent(string $command): Event
{
    return collect(app(Schedule::class)->events())->first(fn (Event $e) => app(Scheduler::class)->commandName($e) === $command);
}

it('lists every scheduled task in plain words, with how often it runs', function () {
    signInAs();

    Livewire::test(ScheduledTasks::class)
        ->assertSee('Store a snapshot of the queue metrics')->assertSee('Every 5 minutes')
        ->assertSee('No task has run yet')->assertSee('Not yet');

    expect(array_column(app(Scheduler::class)->tasks(), 'command'))->toContain('horizon:snapshot', 'scheduler:watch')
        ->and(Frequency::describe('10 4 * * *', 'UTC', 'Asia/Tokyo'))->toBe('Daily at 1:10 PM')
        ->and(Frequency::describe('*/15 * * * *', 'UTC', 'UTC'))->toBe('Every 15 minutes')
        ->and(Frequency::describe('30 * * * *', 'UTC', 'UTC'))->toBe('Every hour, at 30 minutes past')
        ->and(Frequency::describe('0 9 * * 1', 'UTC', 'UTC'))->toBe('Mondays at 9:00 AM')
        ->and(Frequency::describe('5 4 1 * *', 'UTC', 'UTC'))->toBe('5 4 1 * *');
});

it('records each run the scheduler makes, background ones when they finish, and prunes old ones', function () {
    $dispatch = scheduledEvent('horizon:snapshot');
    $alerts = scheduledEvent('scheduler:watch')->runInBackground(); // a background task reports back itself

    event(new ScheduledTaskStarting($dispatch));
    $dispatch->exitCode = 0;
    event(new ScheduledTaskFinished($dispatch, 0.42));
    expect(ScheduledTaskRun::sole())->command->toBe('horizon:snapshot')->source->toBe('schedule')->status->toBe('ok')->runtime_ms->toBe(420)->error->toBeNull()
        ->and(app(Scheduler::class)->lastRunAt())->not->toBeNull()
        ->and(app(Scheduler::class)->stalled())->toBeFalse();

    event(new ScheduledTaskStarting($alerts));
    event(new ScheduledTaskFinished($alerts, 0.01));
    expect(ScheduledTaskRun::where('command', 'scheduler:watch')->sole()->status)->toBe('running');
    $alerts->exitCode = 1;
    event(new ScheduledBackgroundTaskFinished($alerts));
    expect(ScheduledTaskRun::where('command', 'scheduler:watch')->sole())->status->toBe('failed')->error->toBe('Exited with code 1.')->exit_code->toBe(1);

    $dispatch->exitCode = 1;
    event(new ScheduledTaskStarting($dispatch));
    event(new ScheduledTaskFinished($dispatch, 0.2));
    event(new ScheduledTaskFailed($dispatch, new Exception('Scheduled command [horizon:snapshot] failed with exit code [1].')));
    expect(ScheduledTaskRun::where('command', 'horizon:snapshot')->latest('id')->first())->status->toBe('failed')->error->toContain('exit code [1]');

    signInAs();
    Livewire::test(ScheduledTasks::class)
        ->assertSee('Running. The last task started')->assertSee('Failed')->assertSee('Exited with code 1.')
        ->set('open', 'horizon:snapshot')->assertSee('Recent runs')->assertSee('on the timer')->assertSee('took 420 ms');

    ScheduledTaskRun::create(['command' => 'scheduler:watch', 'source' => 'schedule', 'status' => 'ok', 'started_at' => now()->subDays(20)]);
    expect(app(Scheduler::class)->prune())->toBe(1)->and(ScheduledTaskRun::count())->toBe(3);
});

it('warns everywhere, and emails platform admins once, when the scheduler has stopped', function () {
    Notification::fake();
    $admin = signInAs();
    $scheduler = app(Scheduler::class);
    expect($scheduler->stalledMinutes())->toBe(10);

    Cache::put(Scheduler::HEARTBEAT, now()->subMinutes(30)->toIso8601String());
    expect($scheduler->stalled())->toBeTrue();
    $this->get(route('users'))->assertSee('The scheduled tasks have stopped');
    Livewire::test(ScheduledTasks::class)->assertSee('Nothing has run since');

    $scheduler->checkStalled();
    $scheduler->checkStalled();
    Notification::assertSentTo($admin, SchedulerStalled::class);
    Notification::assertSentTimes(SchedulerStalled::class, 1);

    // Running again: no warning, and the next stall is reported too.
    $scheduler->beat();
    expect($scheduler->stalled())->toBeFalse();
    $this->get(route('users'))->assertDontSee('The scheduled tasks have stopped');
    $scheduler->checkStalled();
    Cache::put(Scheduler::HEARTBEAT, now()->subMinutes(30)->toIso8601String());
    $scheduler->checkStalled();
    Notification::assertSentTimes(SchedulerStalled::class, 2);

    signInAs(Role::Viewer);
    $this->get(route('account'))->assertDontSee('scheduled tasks have stopped');
    $this->get(route('scheduled-tasks'))->assertForbidden();
});

it('runs a task now from the page, in the queue, keeping what it printed and who asked', function () {
    $admin = signInAs();

    Livewire::test(ScheduledTasks::class)->call('runNow', 'scheduler:watch')
        ->assertDispatched('saved')->assertSet('open', 'scheduler:watch')
        ->assertSee('run by '.$admin->name)->assertSee('What it printed')->assertSee('Started watching.');
    expect(ScheduledTaskRun::sole())->source->toBe('manual')->status->toBe('ok')->exit_code->toBe(0)->user_id->toBe($admin->id)
        ->and(Activity::where('event', 'task_run')->sole()->description)->toBe('Ran "Keep the queue watching that the scheduled tasks are still running" now');

    Livewire::test(ScheduledTasks::class)->call('runNow', 'db:wipe')->assertDispatched('failed');
    expect(ScheduledTaskRun::count())->toBe(1);

    signInAs(Role::Creator);
    Livewire::test(ScheduledTasks::class)->call('runNow', 'scheduler:watch')->assertForbidden();
});

it('keeps one watch alive in the queue, which carries on by itself', function () {
    Queue::fake();

    $this->artisan('scheduler:watch')->expectsOutputToContain('Started watching.');
    $this->artisan('scheduler:watch')->expectsOutputToContain('Already being watched.');
    Queue::assertPushed(WatchScheduler::class, 1);

    config(['queue.default' => 'redis']);
    (new WatchScheduler(Cache::get(Scheduler::WATCH)))->handle(app(Scheduler::class));
    Queue::assertPushed(WatchScheduler::class, 2); // queued itself again

    (new WatchScheduler('an-older-chain'))->handle(app(Scheduler::class));
    Queue::assertPushed(WatchScheduler::class, 2); // stops
});

it('has its settings on the platform Settings page', function () {
    signInAs();

    Livewire::test(SettingsForm::class, ['scope' => SettingScope::Platform, 'ability' => 'settings.manage', 'batch' => true, 'platformOnly' => true])
        ->assertSee('Count the scheduled tasks as stopped after');
});
