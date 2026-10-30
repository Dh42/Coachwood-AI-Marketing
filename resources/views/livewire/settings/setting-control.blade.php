{{-- A setting's control, bound to $model (such as values.llm__default_model), with id $controlId. Used by setting-row. --}}
    @switch($definition->control)
        @case(\App\Settings\SettingControl::Number)
            <div class="flex items-center gap-2">
                <x-form.input type="number" id="{{ $controlId }}" class="w-28" step="any"
                              wire:model="{{ $model }}" :min="$definition->min" :max="$definition->max" />
                @if ($definition->unit)
                    <span class="text-zinc-500 dark:text-zinc-400">{{ $definition->unit }}</span>
                @endif
            </div>
            @break

        @case(\App\Settings\SettingControl::Toggle)
            <label class="relative inline-flex cursor-pointer items-center gap-2">
                <input type="checkbox" id="{{ $controlId }}" wire:model="{{ $model }}" class="peer sr-only">
                <span class="relative h-5 w-9 rounded-full bg-zinc-300 transition peer-checked:bg-zinc-900 after:absolute after:top-0.5 after:left-0.5 after:size-4 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-4 dark:bg-zinc-700 dark:peer-checked:bg-white dark:after:bg-zinc-900"></span>
            </label>
            @break

        @case(\App\Settings\SettingControl::Select)
            <x-form.select id="{{ $controlId }}" class="max-w-sm" wire:model="{{ $model }}">
                @if ($definition->default === null)
                    <option value="">Not set</option>
                @endif
                @foreach ($definition->optionsFor($scopeId) as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-form.select>
            @break

        @case(\App\Settings\SettingControl::Text)
            <x-form.input id="{{ $controlId }}" class="max-w-sm" wire:model="{{ $model }}" />
            @break

        @case(\App\Settings\SettingControl::Translated)
            <div class="grid max-w-xl gap-2 sm:grid-cols-2">
                @foreach (['en' => 'English', 'fr' => 'French'] as $lang => $language)
                    <label class="block text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $language }}
                        <x-form.input id="{{ $controlId }}-{{ $lang }}" class="mt-0.5" wire:model="{{ $model }}.{{ $lang }}" />
                    </label>
                @endforeach
            </div>
            @break

        @case(\App\Settings\SettingControl::Secret)
            <x-form.input type="password" id="{{ $controlId }}" class="max-w-sm" wire:model="{{ $model }}" autocomplete="off"
                          :placeholder="$secretSaved ? 'Saved. Leave blank to keep it.' : ''" />
            @break

        @case(\App\Settings\SettingControl::Range)
            <div x-data="{ range: $wire.entangle('{{ $model }}'), lo: {{ $definition->min ?? 0 }}, hi: {{ $definition->max ?? 100 }} }" class="max-w-md space-y-3">
                <div class="relative h-6">
                    <div class="absolute top-1/2 h-1.5 w-full -translate-y-1/2 rounded-full bg-zinc-200 dark:bg-zinc-700"></div>
                    <div class="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-zinc-900 dark:bg-white"
                         :style="`left: ${(range.min - lo) / (hi - lo) * 100}%; right: ${100 - (range.max - lo) / (hi - lo) * 100}%`"></div>
                    <input type="range" :min="lo" :max="hi" step="1" aria-label="Shortest"
                           :value="range.min" @input="range = { min: Math.min(+$event.target.value, range.max), max: range.max }"
                           class="range-thumb pointer-events-none absolute inset-0 w-full appearance-none bg-transparent">
                    <input type="range" :min="lo" :max="hi" step="1" aria-label="Longest"
                           :value="range.max" @input="range = { min: range.min, max: Math.max(+$event.target.value, range.min) }"
                           class="range-thumb pointer-events-none absolute inset-0 w-full appearance-none bg-transparent">
                </div>
                <div class="flex items-center gap-2">
                    <x-form.input type="number" id="{{ $controlId }}" class="w-24" x-model.number="range.min" :min="$definition->min" :max="$definition->max" aria-label="Shortest" />
                    <span class="text-zinc-500 dark:text-zinc-400">to</span>
                    <x-form.input type="number" class="w-24" x-model.number="range.max" :min="$definition->min" :max="$definition->max" aria-label="Longest" />
                    @if ($definition->unit)
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $definition->unit }}</span>
                    @endif
                </div>
            </div>
            @break

        @case(\App\Settings\SettingControl::Switches)
            <div x-data="{ off: $wire.entangle('{{ $model }}') }" class="grid max-w-xl gap-1.5 sm:grid-cols-2" id="{{ $controlId }}">
                @foreach ($definition->optionsFor($scopeId) as $value => $label)
                    <label class="inline-flex cursor-pointer items-center gap-2">
                        <input type="checkbox" :checked="! (off || []).includes(@js($value))"
                               @change="off = $event.target.checked ? (off || []).filter(k => k !== @js($value)) : [...(off || []), @js($value)]">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
            @break

        @case(\App\Settings\SettingControl::Checklist)
            <div x-data="{ on: $wire.entangle('{{ $model }}') }" class="grid max-w-xl gap-1.5 sm:grid-cols-2" id="{{ $controlId }}">
                @forelse ($definition->optionsFor($scopeId) as $value => $label)
                    <label class="inline-flex cursor-pointer items-center gap-2">
                        <input type="checkbox" :checked="(on || []).map(String).includes(@js((string) $value))"
                               @change="on = $event.target.checked ? [...(on || []), @js((string) $value)] : (on || []).filter(k => String(k) !== @js((string) $value))">
                        <span>{{ $label }}</span>
                    </label>
                @empty
                    <p class="text-zinc-500 dark:text-zinc-400">Nothing to choose from yet.</p>
                @endforelse
            </div>
            @break

        @case(\App\Settings\SettingControl::List)
            {{-- Save also takes what's still typed in the box, so it isn't lost (or saved as an empty list). --}}
            <div x-data="{ items: $wire.entangle('{{ $model }}'), draft: '', flush() { if (this.draft.trim()) { this.items = [...(this.items || []), this.draft.trim()]; $wire.$set('{{ $model }}', [...this.items], false); this.draft = '' } } }" class="space-y-2"
                 x-init="$el.closest('form').addEventListener('submit', () => flush(), true)" @save-bar-flush.window="flush()" @save-bar-discard.window="draft = ''"
                 @if ($draftField) x-effect="$dispatch('settings-draft', { field: @js($draftField), typed: draft.trim() !== '' })" @endif>
                <ul class="flex flex-wrap gap-1.5">
                    <template x-for="(item, index) in items" :key="item + index">
                        <li class="inline-flex items-center gap-1 rounded-md bg-zinc-100 py-1 pr-1 pl-2 dark:bg-zinc-800">
                            <span x-text="item"></span>
                            <button type="button" class="rounded p-0.5 text-zinc-400 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-white"
                                    @click="items = items.filter((_, i) => i !== index)" :aria-label="'Remove ' + item">
                                <x-heroicon-m-x-mark class="size-3.5" />
                            </button>
                        </li>
                    </template>
                </ul>
                <div class="flex max-w-sm gap-2">
                    <x-form.input id="{{ $controlId }}" x-model="draft" placeholder="Add one and press Enter"
                                  @keydown.enter.prevent="if (draft.trim()) { items = [...items, draft.trim()]; draft = '' }" />
                    <x-button variant="secondary" @click="if (draft.trim()) { items = [...items, draft.trim()]; draft = '' }">Add</x-button>
                </div>
            </div>
            @break
    @endswitch
