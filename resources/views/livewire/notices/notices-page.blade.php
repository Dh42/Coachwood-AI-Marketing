<div class="max-w-3xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-zinc-600 dark:text-zinc-400">
            Everything that happened to your requests: questions from the planner, plans ready to confirm, runs and changes finishing. What you're emailed about is chosen under <a href="{{ route('account') }}#notices" class="underline">Your account</a>.
        </p>
        @if ($unread > 0)
            <x-button size="sm" variant="secondary" wire:click="readAll">Mark all as read</x-button>
        @endif
    </div>

    <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
        @forelse ($notices as $notice)
            <li wire:key="notice-{{ $notice->id }}">
                <button type="button" wire:click="open('{{ $notice->id }}')" @class(['flex w-full items-start gap-3 px-5 py-3 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800', 'bg-sky-50/60 dark:bg-sky-950/30' => $notice->unread()])>
                    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-sky-500' => $notice->unread(), 'bg-transparent' => ! $notice->unread()])></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ $notice->data['title'] ?? 'Notice' }}</span>
                        @if (! empty($notice->data['body']))
                            <span class="block text-zinc-600 dark:text-zinc-400">{{ $notice->data['body'] }}</span>
                        @endif
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $notice->data['brand'] ?? '' }}{{ ! empty($notice->data['brand']) ? ' · ' : '' }}{{ $notice->created_at->diffForHumans() }}</span>
                    </span>
                </button>
            </li>
        @empty
            <li class="px-5 py-8 text-center text-zinc-500 dark:text-zinc-400">Nothing yet. When a request of yours needs you or finishes, it shows here.</li>
        @endforelse
    </ul>

    {{ $notices->links() }}
</div>
