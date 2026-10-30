<div class="max-w-5xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <x-form.select wire:model.live="show" class="w-44" aria-label="Show">
                <option value="all">Everyone's</option>
                <option value="mine">Mine</option>
            </x-form.select>
        </div>
        @if ($canCreate)
            <a href="{{ route('requests.create', $brand) }}" class="inline-flex items-center gap-1.5 rounded-md bg-zinc-900 px-3 py-2 font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <x-heroicon-m-plus class="size-4" /> New request
            </a>
        @endif
    </div>

    @if ($requests->isEmpty())
        <p class="rounded-lg border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            No requests yet. {{ $canCreate ? 'Press "New request" and say what you need.' : '' }}
        </p>
    @else
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
            @foreach ($requests as $request)
                <a wire:key="request-{{ $request->id }}" href="{{ route('requests.show', [$brand, $request]) }}" class="flex flex-wrap items-start justify-between gap-2 px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                    <div class="min-w-0">
                        <div class="font-medium">{{ $request->title ?: Str::limit($request->brief, 80) }} @unless ($request->public) <span class="ml-1 rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-normal text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Private</span> @endunless</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $request->user->name }} · {{ $request->created_at->diffForHumans() }}{{ $request->estimated_cost !== null ? ' · about $'.\App\Budgets\Budgets::money($request->estimated_cost) : '' }}</div>
                    </div>
                    <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300' => $request->status === 'asking', 'bg-green-100 text-green-800 dark:bg-green-950/50 dark:text-green-300' => in_array($request->status, ['planned', 'done']), 'bg-sky-100 text-sky-800 dark:bg-sky-950/50 dark:text-sky-300' => in_array($request->status, ['ready', 'running']), 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-300' => $request->status === 'failed', 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => ! in_array($request->status, ['asking', 'planned', 'ready', 'running', 'done', 'failed'])])>{{ $request->statusLabel() }}</span>
                </a>
            @endforeach
        </div>
        {{ $requests->links() }}
    @endif
</div>
