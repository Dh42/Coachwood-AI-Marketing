@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <x-theme-script />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full bg-zinc-50 font-sans text-sm text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    {{-- The shell never scrolls; only the main area (and a side panel) does. --}}
    <div class="flex h-screen overflow-hidden">
        <x-sidebar />

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 shrink-0 items-center justify-between gap-4 border-b border-zinc-200 bg-white px-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h1 class="truncate text-base font-semibold">{{ $title }}</h1>

                @php($settingsArea = app(\App\Settings\SettingsAreas::class)->forPage(request()->route()?->getName()))
                @php($routeBrand = request()->route('brand'))
                <div class="flex items-center gap-2">
                    @auth
                        <x-quick-find />
                    @endauth
                    @if ($settingsArea && $routeBrand instanceof \App\Models\Brand)
                        <a href="{{ $settingsArea->url($routeBrand) }}" data-settings-link
                           class="inline-flex items-center gap-1.5 rounded-md border border-zinc-300 px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            <x-heroicon-o-cog-6-tooth class="size-4" /> Settings
                        </a>
                    @endif
                    @isset($actions)
                        {{ $actions }}
                    @endisset
                </div>
            </header>

            @auth
                @php($scheduler = app(\App\Scheduler\Scheduler::class))
                @if (auth()->user()->can('scheduler.manage') && $scheduler->stalled())
                    <p class="border-b border-red-200 bg-red-50 px-6 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                        The scheduled tasks have stopped: nothing has run since {{ $scheduler->lastRunAt()->timezone(app(\App\Settings\Settings::class)->get('platform.timezone'))->format('M j, g:i A') }}.
                        <a href="{{ route('scheduled-tasks') }}" class="underline">See what to check</a>.
                    </p>
                @endif
            @endauth

            <div class="flex min-h-0 flex-1">
                {{-- relative: absolutely positioned content (such as visually hidden
                     checkboxes) stays inside the scroll area instead of stretching the page. --}}
                <main class="relative min-w-0 flex-1 overflow-y-auto p-6">
                    {{ $slot }}
                </main>

                @isset($panel)
                    <aside class="relative w-96 shrink-0 overflow-y-auto border-l border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                        {{ $panel }}
                    </aside>
                @endisset
            </div>
        </div>
    </div>

    <x-toasts />

    {{-- Loaded on every page, not only ones with a Livewire component, because the shell uses Alpine. --}}
    @livewireScripts
</body>
</html>
