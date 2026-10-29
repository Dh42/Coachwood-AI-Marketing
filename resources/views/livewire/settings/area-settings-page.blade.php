<div @class(['pb-20' => $canManage])>
    @if ($canManage)
        <x-save-bar />
    @endif

    <x-settings-hub :brand="$brand" :current="$settingsArea->key">
        <div class="max-w-3xl space-y-6">
            <div class="flex items-start justify-between gap-4">
                <p class="text-zinc-500 dark:text-zinc-400">{{ $settingsArea->description ?: "How {$settingsArea->title} works for {$brand->name}. Anything not changed here follows the platform's settings." }}</p>
                @if ($back)
                    <a href="{{ $back }}" class="inline-flex shrink-0 items-center gap-1 font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                        Go to {{ $settingsArea->title }} <x-heroicon-m-arrow-right class="size-4" />
                    </a>
                @endif
            </div>

            @if ($settingsArea->component)
                <livewire:dynamic-component :is="$settingsArea->component" :brand="$brand" :key="'area-component-'.$settingsArea->key.'-'.$brand->id" />
            @endif

            <livewire:settings.settings-form :scope="\App\Settings\SettingScope::Brand" :scope-id="$brand->id" ability="brands.manage" :sections="$settingsArea->sections" :batch="true" :key="'area-'.$settingsArea->key.'-'.$brand->id" />

            <x-for-every-brand :sections="$settingsArea->sections" :key="$settingsArea->key" class="border-t border-zinc-200 pt-6 dark:border-zinc-800" />
        </div>
    </x-settings-hub>
</div>
