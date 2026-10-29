@php
    use App\Models\ScheduledTaskRun;

    $took = fn (?int $ms) => $ms === null ? '' : ($ms < 1000 ? $ms.' ms' : number_format($ms / 1000, 1).' s');
    $when = fn ($at) => $at?->timezone($tz)->format('M j, g:i:s A');
    $chips = [
        ScheduledTaskRun::OK => ['Finished', 'bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-300'],
        ScheduledTaskRun::FAILED => ['Failed', 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300'],
        ScheduledTaskRun::RUNNING => ['Running now', 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'],
        ScheduledTaskRun::QUEUED => ['Waiting its turn', 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'],
    ];
    $chip = fn (string $status) => '<span class="rounded-full px-2 py-0.5 text-xs font-medium '.$chips[$status][1].'">'.$chips[$status][0].'</span>';
@endphp

<div class="max-w-6xl space-y-4" wire:poll.30s x-data="{ advanced: false }">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-3xl text-zinc-600 dark:text-zinc-400">
            The checks, syncs and clean-ups that run on a timer on the server. Most only do work when something is due: how often a group is checked or synced is in its own settings.
            @if ($horizonUrl)
                The replies, grading and imports that run as they come in are in <a href="{{ $horizonUrl }}" target="_blank" rel="noopener" class="underline">Horizon</a>.
            @endif
        </p>
        <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
            <input type="checkbox" x-model="advanced" class="rounded border-zinc-300 dark:border-zinc-700"> Advanced
        </label>
    </div>

    {{-- Is the scheduler itself running? --}}
    <section data-tour="scheduler-status" @class([
        'rounded-lg border px-4 py-3',
        'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200' => $stalled,
        'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-200' => ! $stalled && $lastRunAt,
        'border-zinc-200 bg-white text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300' => ! $lastRunAt,
    ])>
        @if ($stalled)
            <p class="font-medium">The scheduled tasks have stopped.</p>
            <p class="mt-1 text-sm">
                Nothing has run since {{ $when($lastRunAt) }} ({{ $lastRunAt->diffForHumans() }}); it's checked every minute.
                Until it's going again, nothing that runs on a timer happens: no syncs, no clean-ups and no summaries.
                The scheduler's entry on the server has probably stopped. Platform admins are emailed when this happens.
            </p>
        @elseif ($lastRunAt)
            <p class="font-medium">Running. The last task started {{ $lastRunAt->diffForHumans() }}.</p>
            <p class="mt-1 text-sm">It counts as stopped after {{ $stalledMinutes }} {{ Str::plural('minute', $stalledMinutes) }} without a task; change that in Settings.</p>
        @else
            <p class="font-medium">No task has run yet.</p>
            <p class="mt-1 text-sm">Once the scheduler's entry is set up on the server, every run shows here.</p>
        @endif
        <div x-cloak x-show="advanced" class="mt-2 text-xs">
            The server needs a cron entry for the site's user that runs every minute (docs/deploy.md, step 8):
            <code class="rounded bg-black/5 px-1 dark:bg-white/10">* * * * * cd SITE_PATH && php artisan schedule:run</code>.
            The queue (Horizon) watches for the scheduler stopping, since the scheduler can't report that itself.
        </div>
    </section>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
        <table class="w-full text-left">
            <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2 font-medium">Task</th>
                    <th class="px-4 py-2 font-medium">How often</th>
                    <th class="px-4 py-2 font-medium">Last run</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @foreach ($tasks as $task)
                    @php($run = $task->lastRun)
                    <tr wire:key="task-{{ $task->command }}" class="align-top">
                        <td class="px-4 py-3">
                            <button type="button" wire:click="$set('open', '{{ $open === $task->command ? '' : $task->command }}')" class="text-left font-medium hover:underline" aria-expanded="{{ $open === $task->command ? 'true' : 'false' }}">{{ $task->description }}</button>
                            <div x-cloak x-show="advanced" class="mt-0.5 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $task->command }} · {{ $task->expression }}{{ $task->background ? ' · in the background' : '' }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm whitespace-nowrap">{{ $task->every }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if ($run)
                                <div class="flex flex-wrap items-center gap-2">
                                    {!! $chip($run->status) !!}
                                    <span title="{{ $when($run->started_at) }}">{{ $run->started_at->diffForHumans() }}</span>
                                    @if ($run->runtime_ms !== null)<span class="text-zinc-500 dark:text-zinc-400">took {{ $took($run->runtime_ms) }}</span>@endif
                                    @if ($run->source === ScheduledTaskRun::MANUAL)<span class="text-zinc-500 dark:text-zinc-400">by {{ $run->user?->name ?? 'someone' }}</span>@endif
                                </div>
                                @if ($run->error)<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $run->error }}</p>@endif
                            @else
                                <span class="text-zinc-500 dark:text-zinc-400">Not yet</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <x-button size="sm" variant="secondary" data-tour="scheduler-run-now" wire:click="runNow('{{ $task->command }}')" wire:loading.attr="disabled" wire:target="runNow('{{ $task->command }}')">Run now</x-button>
                        </td>
                    </tr>
                    @if ($open === $task->command)
                        <tr wire:key="runs-{{ $task->command }}">
                            <td colspan="4" class="bg-zinc-50 px-4 py-3 dark:bg-zinc-950/40">
                                @if ($runs->isEmpty())
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">This task hasn't run yet.</p>
                                @else
                                    <p class="mb-2 text-xs font-medium text-zinc-500 uppercase dark:text-zinc-400">Recent runs</p>
                                    <ol class="divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                                        @foreach ($runs as $r)
                                            <li wire:key="run-{{ $r->id }}" class="py-2">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    {!! $chip($r->status) !!}
                                                    <span>{{ $when($r->started_at) }}</span>
                                                    @if ($r->runtime_ms !== null)<span class="text-zinc-500 dark:text-zinc-400">took {{ $took($r->runtime_ms) }}</span>@endif
                                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $r->source === ScheduledTaskRun::MANUAL ? 'run by '.($r->user?->name ?? 'someone') : 'on the timer' }}</span>
                                                </div>
                                                @if ($r->error)<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $r->error }}</p>@endif
                                                @if ($r->output)
                                                    <details class="mt-1 text-xs">
                                                        <summary class="cursor-pointer text-zinc-500 dark:text-zinc-400">What it printed</summary>
                                                        <pre class="mt-1 max-h-64 overflow-auto rounded bg-white p-2 whitespace-pre-wrap dark:bg-zinc-900">{{ $r->output }}</pre>
                                                    </details>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-xs text-zinc-500 dark:text-zinc-400">"Run now" starts the task straight away in the background, alongside anything already running. Runs are kept for a while (Settings → Scheduled tasks).</p>
</div>
