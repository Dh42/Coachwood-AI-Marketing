{{-- Connection fields for a plugin. Expects $plugin and, when editing, $connection. --}}
@php
    $editing = isset($connection);
    $basic = collect($plugin->connectionFields())->reject->advanced;
    $advanced = collect($plugin->connectionFields())->filter->advanced;
@endphp

@foreach ([$basic, $advanced] as $brand)
    @if ($brand->isNotEmpty())
        @if ($loop->last)
            <details class="rounded-md border border-zinc-200 p-3 dark:border-zinc-800" @if ($errors->has('fields.*') || ! $editing) open @endif>
                <summary class="cursor-pointer font-medium select-none">Advanced</summary>
                <div class="mt-3 space-y-4">
        @endif

        @foreach ($brand as $field)
            @php($saved = $editing && $field->secret && filled($connection->secret($field->key)))
            <x-form.field :label="$field->label" name="fields.{{ $field->key }}"
                          :help="$saved ? 'Saved. Leave blank to keep it, or type a new one to replace it.' : $field->help">
                @if ($field->options)
                    <x-form.select wire:model="fields.{{ $field->key }}" id="fields.{{ $field->key }}">
                        <option value="">Choose…</option>
                        @foreach ($field->options as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                @elseif ($field->multiline)
                    <textarea wire:model="fields.{{ $field->key }}" id="fields.{{ $field->key }}" rows="5" placeholder="{{ $field->placeholder }}"
                              class="block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 font-mono text-xs shadow-xs focus:border-zinc-500 focus:ring-2 focus:ring-zinc-200 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800"></textarea>
                @else
                    <x-form.input :type="$field->secret ? 'password' : 'text'" wire:model="fields.{{ $field->key }}" id="fields.{{ $field->key }}"
                                  :placeholder="$saved ? '••••••••' : $field->placeholder" autocomplete="off" />
                @endif
            </x-form.field>
        @endforeach

        @if ($loop->last)
                </div>
            </details>
        @endif
    @endif
@endforeach
