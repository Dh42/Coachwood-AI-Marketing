{{-- A feature's platform-only settings ("For every brand"), in its entry of a brand's Settings, for those who can change platform settings. --}}
@props(['sections', 'key', 'batch' => true])

@php
    $shown = auth()->user()?->can('settings.manage') && app(\App\Settings\SettingsRegistry::class)->settableAt(\App\Settings\SettingScope::Platform)
        ->contains(fn (\App\Settings\SettingDefinition $d) => $d->isPlatformOnly() && $d->control !== \App\Settings\SettingControl::Custom && in_array($d->section, $sections, true));
@endphp

@if ($shown)
    <section data-for-every-brand {{ $attributes->class(['space-y-3']) }}>
        <div>
            <h2 class="text-base font-semibold">For every brand</h2>
            <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">Set once for the whole platform: a brand can't have its own. Only admins see these.</p>
        </div>
        <livewire:settings.settings-form :scope="\App\Settings\SettingScope::Platform" ability="settings.manage" :sections="$sections" :platform-only="true" :batch="$batch" :key="'every-brand-'.$key" />
    </section>
@endif
