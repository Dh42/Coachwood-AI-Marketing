<div class="pb-20">
    <x-save-bar />

    <p class="mb-6 max-w-3xl text-zinc-600 dark:text-zinc-400">
        What applies to the whole platform. Each feature's settings are in a brand's Settings: there, beside each brand's own value, admins see the default for all brands and can change it, and a feature's platform-wide settings are under "For every brand".
    </p>

    @if (session('status'))
        <p class="mb-6 max-w-3xl rounded-md bg-green-50 px-3 py-2 text-green-800 dark:bg-green-950/50 dark:text-green-300">{{ session('status') }}</p>
    @endif

    <div class="mb-8 space-y-8">
        <livewire:settings.email-connection />

        @if ($corePlugins)
            <section class="max-w-3xl">
                <h2 class="mb-2 font-semibold">Platform features (core plugins)</h2>
                <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                    @foreach ($corePlugins as $plugin)
                        <li class="px-4 py-3">
                            <div class="font-medium">{{ $plugin->name() }}</div>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $plugin->description() }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    {{-- Only what belongs to no feature: the rest is in each feature's entry of a brand's Settings. --}}
    <livewire:settings.settings-form :scope="\App\Settings\SettingScope::Platform" ability="settings.manage" :batch="true" :platform-only="true" :except="$homed" />
</div>
