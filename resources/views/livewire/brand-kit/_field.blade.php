{{-- One field of a kit item: $field (App\BrandKit\Field), $key (the row), $options (choices by field), $canManage, $compact (in a table cell: no label). --}}
@php($model = "rows.{$key}.{$field->key}")
@php($id = "kit-{$key}-{$field->key}")
<div>
    @unless ($compact ?? false)
        <label for="{{ $id }}" class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $field->label }}</label>
    @endunless
    @switch($field->type)
        @case(\App\BrandKit\FieldType::Translated)
            <div class="grid gap-1.5 sm:grid-cols-2">
                @foreach (['en' => 'English', 'fr' => 'French'] as $lang => $language)
                    <div>
                        <x-form.input wire:model="{{ $model }}.{{ $lang }}" id="{{ $id }}-{{ $lang }}" class="py-1" :placeholder="$language" :aria-label="$field->label.' ('.$language.')'" :disabled="! $canManage" />
                        @error("{$model}.{$lang}") <p class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            @break

        @case(\App\BrandKit\FieldType::Select)
            <x-form.select wire:model="{{ $model }}" id="{{ $id }}" class="py-1" :aria-label="$field->label" :disabled="! $canManage">
                @foreach ($options[$field->key] ?? [] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-form.select>
            @break

        @case(\App\BrandKit\FieldType::Toggle)
            <label class="inline-flex items-center gap-2 py-1.5">
                <input type="checkbox" wire:model="{{ $model }}" id="{{ $id }}" @disabled(! $canManage) class="size-4 rounded border-zinc-300 dark:border-zinc-600" />
                <span class="text-xs text-zinc-700 dark:text-zinc-300">{{ $field->label }}</span>
            </label>
            @break

        @default
            <x-form.input wire:model="{{ $model }}" id="{{ $id }}" class="py-1" :placeholder="$field->placeholder" :aria-label="$field->label" :disabled="! $canManage" />
    @endswitch
    @if ($field->help && ! ($compact ?? false))
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $field->help }}</p>
    @endif
    @if ($field->type !== \App\BrandKit\FieldType::Translated)
        @error($model) <p class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    @endif
</div>
