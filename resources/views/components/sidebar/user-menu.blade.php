@auth
    <div x-data="{ open: false }" class="relative" @click.outside="open = false" @keydown.escape="open = false">
        <button type="button" @click="open = ! open" :aria-expanded="open"
                class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-200 text-xs font-semibold dark:bg-zinc-700">
                {{ \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate font-medium">{{ auth()->user()->name }}</span>
                <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ auth()->user()->email }}</span>
            </span>
            <x-heroicon-m-chevron-up-down class="size-4 shrink-0 text-zinc-400" />
        </button>

        <div x-cloak x-show="open" x-transition.opacity.duration.100ms
             class="absolute inset-x-0 bottom-full z-20 mb-1 rounded-md border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
            <a href="{{ route('account') }}" class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                <x-heroicon-m-user-circle class="size-4 text-zinc-400" />
                Your account
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    <x-heroicon-m-arrow-right-start-on-rectangle class="size-4 text-zinc-400" />
                    Sign out
                </button>
            </form>
        </div>
    </div>
@endauth
