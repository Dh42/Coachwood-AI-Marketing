<?php

namespace App\Livewire\Scheduler;

use App\Models\ScheduledTaskRun;
use App\Scheduler\Scheduler;
use App\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The tasks on the server's timer: whether the scheduler is running, what
 * each task last did, and a button to run one now.
 */
#[Title('Scheduled tasks')]
class ScheduledTasks extends Component
{
    /** The task whose recent runs are open. */
    #[Url]
    public string $open = '';

    public function runNow(string $command, Scheduler $scheduler): void
    {
        $this->authorize('scheduler.manage');

        try {
            $scheduler->run($command, Auth::user());
        } catch (InvalidArgumentException $e) {
            $this->dispatch('failed', message: $e->getMessage());

            return;
        }

        $this->open = $command;
        $this->dispatch('saved', message: "Running \"{$scheduler->describe($command)}\" now. Its result shows under the task in a moment.");
    }

    public function render(Scheduler $scheduler, Settings $settings)
    {
        $tasks = $scheduler->tasks();

        if ($this->open !== '' && ! in_array($this->open, array_column($tasks, 'command'), true)) {
            $this->open = '';
        }

        return view('livewire.scheduler.scheduled-tasks', [
            'tasks' => $tasks,
            'lastRunAt' => $scheduler->lastRunAt(),
            'stalled' => $scheduler->stalled(),
            'stalledMinutes' => $scheduler->stalledMinutes(),
            'runs' => $this->open === '' ? collect() : ScheduledTaskRun::where('command', $this->open)->with('user')->latest('id')->take(10)->get(),
            'tz' => $settings->get('platform.timezone'),
            'horizonUrl' => Auth::user()->can('viewHorizon') ? url(config('horizon.path')) : null,
        ]);
    }
}
