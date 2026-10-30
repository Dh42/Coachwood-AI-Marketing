<form wire:submit="save" class="grid gap-6 lg:grid-cols-[1fr_340px]">
    <div class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-form.field label="Name" name="form.name">
                <x-form.input wire:model="form.name" id="form.name" />
            </x-form.field>
            <x-form.field label="Shape" name="form.ratio" help="The shape this layout is for.">
                <x-form.select wire:model="form.ratio" id="form.ratio">
                    @foreach (array_unique([...$ratios, $form['ratio'] ?? '1:1']) as $r)
                        <option value="{{ $r }}">{{ $r }}</option>
                    @endforeach
                </x-form.select>
            </x-form.field>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (\App\Models\LayoutTemplate::OPTIONS as $key => $options)
                <x-form.field :label="['headline_position' => 'Headline', 'align' => 'Text alignment', 'text_size' => 'Text size', 'text_colour' => 'Text colour', 'overlay' => 'Darken the picture behind the words', 'logo_corner' => 'Logo', 'logo_size' => 'Logo size', 'product_position' => 'Product', 'product_size' => 'Product size', 'cta' => 'Call to action'][$key]" name="form.{{ $key }}">
                    <x-form.select wire:model="form.{{ $key }}" id="form.{{ $key }}">
                        @foreach ($options as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-form.select>
                </x-form.field>
            @endforeach
        </div>
        <div class="space-y-1">
            @foreach (\App\Models\LayoutTemplate::TOGGLES as $key => $label)
                <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="form.{{ $key }}" class="rounded border-zinc-300 dark:border-zinc-700"> {{ $label }}</label>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2">
            <x-button type="submit" size="sm" wire:loading.attr="disabled">Save layout</x-button>
            <x-button type="button" size="sm" variant="secondary" wire:click="showPreview" wire:loading.attr="disabled" wire:target="showPreview">Preview</x-button>
            <x-button type="button" size="sm" variant="ghost" wire:click="close">Cancel</x-button>
        </div>
    </div>
    <div>
        <div class="text-xs text-zinc-500 dark:text-zinc-400">Preview, with the brand kit's logo, fonts and colours on a plain backdrop.</div>
        <div class="mt-1.5 flex min-h-48 items-center justify-center rounded-md border border-dashed border-zinc-300 bg-zinc-50 p-2 dark:border-zinc-700 dark:bg-zinc-950/40" wire:loading.class="opacity-50" wire:target="showPreview">
            @if ($previewError)
                <p class="text-xs text-red-600 dark:text-red-400">{{ $previewError }}</p>
            @elseif ($preview)
                <img src="{{ $preview }}" alt="Preview" class="max-h-96 max-w-full rounded" />
            @else
                <p class="text-xs text-zinc-400">Press Preview.</p>
            @endif
        </div>
    </div>
</form>
