{{-- A brand's Settings: the list of entries down the side (SettingsAreas), and the open one beside it. --}}
@props(['brand', 'current'])

@php
    $menu = app(\App\Settings\SettingsAreas::class)->menu(auth()->user(), $brand);
    $top = $menu !== [] && $menu[0]['heading'] === null ? array_shift($menu)['areas'] : [];
    $link = fn (bool $active) => [
        'block rounded-md px-2.5 py-1.5',
        'bg-zinc-200/70 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-white' => $active,
        'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' => ! $active,
    ];
@endphp

<div class="flex flex-col gap-6 lg:flex-row lg:items-start">
    <nav data-settings-menu aria-label="Settings" data-tour="settings-menu" class="shrink-0 space-y-4 lg:sticky lg:top-0 lg:w-52">
        <ul class="space-y-0.5">
            <li><a href="{{ route('brand-settings', $brand) }}" wire:navigate @if ($current === 'brand') aria-current="page" @endif @class($link($current === 'brand'))>Brand</a></li>
            @foreach ($top as $area)
                <li><a href="{{ $area->url($brand) }}" wire:navigate @if ($current === $area->key) aria-current="page" @endif @class($link($current === $area->key))>{{ $area->title }}</a></li>
            @endforeach
        </ul>

        @foreach ($menu as $entry)
            <div>
                <p class="mb-1 px-2.5 text-xs font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ $entry['heading'] }}</p>
                <ul class="space-y-0.5">
                    @foreach ($entry['areas'] as $area)
                        <li><a href="{{ $area->url($brand) }}" wire:navigate @if ($current === $area->key) aria-current="page" @endif @class($link($current === $area->key))>{{ $area->title }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="min-w-0 flex-1">
        {{ $slot }}
    </div>
</div>
