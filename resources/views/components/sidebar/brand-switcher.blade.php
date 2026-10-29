@props(['current', 'brands'])

@php
    // Switching keeps you on the same screen, in the other brand. Plain words in the
    // address (a feature's settings page) carry over; a record (one request) doesn't,
    // so switching goes to the screen itself.
    $routeName = request()->route()?->getName();
    $keep = $routeName ? collect(request()->route()->parameters())->except('brand')->filter(fn ($p) => is_string($p))->all() : [];
    if ($routeName && count(request()->route()->parameterNames()) > 1 + count($keep)) {
        [$routeName, $keep] = [Str::before($routeName, '.'), []];
    }
    $staysOnScreen = $current && request()->route('brand') && $routeName && Route::has($routeName);
    $urlFor = fn ($b) => $staysOnScreen ? route($routeName, ['brand' => $b, ...$keep]) : route('brand-settings', $b);
@endphp

<div x-data="{ open: false }" class="relative" @click.outside="open = false" @keydown.escape="open = false">
    <button type="button" @click="open = ! open" :aria-expanded="open"
            class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
        <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $current?->color ?? '#d4d4d8' }}"></span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold">{{ $current?->name ?? 'No brand' }}</span>
            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ config('app.name') }}</span>
        </span>
        <x-heroicon-m-chevron-up-down class="size-4 shrink-0 text-zinc-400" />
    </button>

    <div x-cloak x-show="open" x-transition.opacity.duration.100ms
         class="absolute inset-x-0 top-full z-20 mt-1 max-h-96 overflow-y-auto rounded-md border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
        @forelse ($brands as $b)
            <a href="{{ $urlFor($b) }}"
               @class(['flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800', 'bg-zinc-100 dark:bg-zinc-800' => $current?->is($b)])>
                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $b->color }}"></span>
                <span class="min-w-0 flex-1 truncate font-medium">{{ $b->name }}</span>
            </a>
        @empty
            <p class="px-2 py-3 text-center text-zinc-500 dark:text-zinc-400">No brands yet.</p>
        @endforelse

        @can('brands.create')
            <div class="mt-1 border-t border-zinc-200 pt-1 dark:border-zinc-700">
                <a href="{{ route('brands.create') }}" class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                    <x-heroicon-m-plus class="size-4" />
                    New brand
                </a>
            </div>
        @endcan
    </div>
</div>
