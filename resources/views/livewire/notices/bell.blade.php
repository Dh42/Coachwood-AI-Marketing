{{-- The bell: unread count, and the latest notices when opened. --}}
<div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" class="relative" wire:poll.60s>
    <button type="button" @click="open = ! open; if (open) $wire.show()" data-notices
            class="relative inline-flex items-center rounded-md border border-zinc-300 px-2 py-1 text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
            aria-label="Notices{{ $unread ? ", {$unread} unread" : '' }}" :aria-expanded="open">
        <x-heroicon-o-bell class="size-4" />
        @if ($unread > 0)
            <span class="absolute -top-1.5 -right-1.5 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] leading-4 font-semibold text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    <div x-cloak x-show="open" x-transition.opacity class="absolute right-0 z-40 mt-2 w-96 overflow-hidden rounded-lg border border-zinc-200 bg-white text-left shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
            <span class="font-semibold">Notices</span>
            @if ($unread > 0)
                <button type="button" wire:click="readAll" class="text-xs text-zinc-500 underline hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Mark all as read</button>
            @endif
        </div>

        <ul class="max-h-[60vh] divide-y divide-zinc-100 overflow-y-auto dark:divide-zinc-800">
            @forelse ($notices as $notice)
                <li wire:key="notice-{{ $notice->id }}">
                    <button type="button" wire:click="open('{{ $notice->id }}')" @class(['flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800', 'bg-sky-50/60 dark:bg-sky-950/30' => $notice->unread()])>
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-sky-500' => $notice->unread(), 'bg-transparent' => ! $notice->unread()])></span>
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ $notice->data['title'] ?? 'Notice' }}</span>
                            @if (! empty($notice->data['body']))
                                <span class="block text-zinc-600 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($notice->data['body'], 140) }}</span>
                            @endif
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $notice->data['brand'] ?? '' }}{{ ! empty($notice->data['brand']) ? ' · ' : '' }}{{ $notice->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-zinc-500 dark:text-zinc-400">{{ $opened ? 'Nothing yet. When a request of yours needs you or finishes, it shows here.' : 'Loading…' }}</li>
            @endforelse
        </ul>

        <div class="border-t border-zinc-200 px-4 py-2 text-xs dark:border-zinc-700">
            <a href="{{ route('notices') }}" class="text-zinc-500 underline hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">See every notice</a>
            <span class="text-zinc-400"> · </span>
            <a href="{{ route('account') }}#notices" class="text-zinc-500 underline hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">What you're emailed about</a>
        </div>
    </div>
</div>
