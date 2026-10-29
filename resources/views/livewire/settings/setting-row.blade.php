{{-- One setting: label, badges, its control, errors and actions. Used by settings-form. --}}
@php($definition = $resolved->definition)
<form wire:key="setting-{{ $field }}" id="setting-row-{{ $field }}" @if ($batch) @submit.prevent="$dispatch('save-bar-request')" @else wire:submit="save('{{ $field }}')" @endif class="space-y-3 p-5">
    <fieldset @disabled($readOnly) class="space-y-3">
    <div class="flex items-start justify-between gap-4">
        <div>
            <label for="setting-{{ $field }}" class="font-medium">{{ $definition->label }}</label>
            <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ $definition->description }}</p>
        </div>

        @if ($batch && ! $readOnly)
            <span x-cloak x-show="dirty.includes(@js($field))" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-300">Unsaved</span>
        @endif
        @if ($resolved->source === $scope)
            <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">Changed</span>
        @elseif ($resolved->isDefault())
            <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">Default</span>
        @else
            <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">From {{ strtolower($resolved->source->label()) }}</span>
        @endif
    </div>

    @include('livewire.settings.setting-control', ['model' => "values.{$field}", 'controlId' => "setting-{$field}", 'draftField' => $field, 'secretSaved' => ! $resolved->isDefault()])

    </fieldset>

    @foreach ($errors->get("values.{$field}*") as $messages)
        @foreach ((array) $messages as $message)
            <p data-save-error class="text-red-600 dark:text-red-400">{{ $message }}</p>
        @endforeach
    @endforeach

    {{-- For those who can change platform settings: the default for all brands, changed from here. --}}
    @if ($platform)
        <div x-data="{ open: false }" x-on:platform-saved.window="if ($event.detail.field === @js($field)) open = false" data-platform-default class="text-zinc-500 dark:text-zinc-400">
            <p>
                Default for all brands: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $definition->describe($platform->value, $scopeId) }}</span>
                · <button type="button" class="underline hover:text-zinc-900 dark:hover:text-white" x-on:click="open = ! open" x-text="open ? 'Close' : 'Change'">Change</button>
            </p>
            <div x-cloak x-show="open" x-on:keydown.enter.prevent class="mt-2 space-y-3 rounded-md border border-dashed border-zinc-300 p-3 dark:border-zinc-700">
                <p class="text-xs">Changes it for every brand that hasn't set its own. Saved straight away, apart from this page's other changes.</p>
                @include('livewire.settings.setting-control', ['model' => "platformValues.{$field}", 'controlId' => "setting-{$field}-platform", 'draftField' => null, 'secretSaved' => ! $platform->isDefault()])
                @foreach ($errors->get("platformValues.{$field}*") as $messages)
                    @foreach ((array) $messages as $message)
                        <p class="text-red-600 dark:text-red-400">{{ $message }}</p>
                    @endforeach
                @endforeach
                <x-button size="sm" variant="secondary" wire:click="savePlatform('{{ $field }}')">Save for all brands</x-button>
            </div>
        </div>
    @endif

    <div @class(['flex gap-2', 'hidden' => $readOnly || ($batch && $resolved->source !== $scope)])>
        @unless ($batch)
            <x-button type="submit" size="sm" wire:loading.attr="disabled" wire:target="save('{{ $field }}')">Save</x-button>
        @endunless
        @if ($resolved->source === $scope)
            <x-button size="sm" variant="ghost" wire:click="resetToDefault('{{ $field }}')">
                {{ $scope === \App\Settings\SettingScope::Platform ? 'Reset to default' : 'Use inherited value' }}
            </x-button>
        @endif
    </div>
</form>
