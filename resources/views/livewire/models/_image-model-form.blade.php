<form wire:submit="saveImage" class="max-w-3xl space-y-4">
    <div class="grid gap-4 sm:grid-cols-3">
        <x-form.field label="Provider" name="imageForm.provider">
            <x-form.select wire:model="imageForm.provider" id="imageForm.provider">
                <option value="">Choose…</option>
                @foreach ($providers as $p)
                    <option value="{{ $p['key'] }}">{{ $p['name'] }}</option>
                @endforeach
            </x-form.select>
        </x-form.field>
        <x-form.field label="Model ID" name="imageForm.model" help="The provider's own ID, as its docs give it.">
            <x-form.input wire:model="imageForm.model" id="imageForm.model" class="font-mono text-xs" placeholder="gemini-nano-banana-2.1" />
        </x-form.field>
        <x-form.field label="Name" name="imageForm.label" help="What people see.">
            <x-form.input wire:model="imageForm.label" id="imageForm.label" />
        </x-form.field>
    </div>

    <x-form.field label="Shapes it makes" name="imageForm.aspect_ratios">
        <div class="flex flex-wrap gap-x-4 gap-y-1">
            @foreach ($ratios as $ratio)
                <label class="inline-flex items-center gap-1.5"><input type="checkbox" wire:model="imageForm.aspect_ratios" value="{{ $ratio }}" class="rounded border-zinc-300 dark:border-zinc-700"> {{ $ratio }}</label>
            @endforeach
        </div>
    </x-form.field>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-form.field label="Sizes it makes" name="imageForm.sizes">
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                @foreach ($sizes as $size => $label)
                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" wire:model.live="imageForm.sizes" value="{{ $size }}" class="rounded border-zinc-300 dark:border-zinc-700"> {{ $size }} <span class="text-xs text-zinc-500">({{ $label }})</span></label>
                @endforeach
            </div>
        </x-form.field>
        <x-form.field label="Qualities" name="imageForm.qualities" help="The provider's quality names, lowest first, separated by commas. Leave blank when the model has none.">
            <x-form.input wire:model.live.debounce.500ms="imageForm.qualities" id="imageForm.qualities" placeholder="low, medium, high" />
        </x-form.field>
    </div>

    <div class="grid gap-4 sm:grid-cols-4">
        <x-form.field label="Reference images" name="imageForm.max_references" help="How many it takes in one request. 0 for none.">
            <x-form.input type="number" min="0" max="100" wire:model="imageForm.max_references" id="imageForm.max_references" />
        </x-form.field>
        <div class="space-y-1.5 pt-6">
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="imageForm.edits" class="rounded border-zinc-300 dark:border-zinc-700"> Edits images</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="imageForm.transparent" class="rounded border-zinc-300 dark:border-zinc-700"> Transparent backgrounds</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="imageForm.renders_text" class="rounded border-zinc-300 dark:border-zinc-700"> Renders text well</label>
        </div>
    </div>

    <x-form.field label="Estimated price per image" name="imageForm.prices" help="US dollars, from the provider's price list, for each size (and quality). These feed the cost shown before a run.">
        <div class="grid gap-2 sm:grid-cols-3">
            @forelse ($priceKeys as $key)
                <label class="flex items-center gap-2 text-xs"><span class="w-24 shrink-0">{{ $key }}</span><x-form.input type="number" step="0.0001" min="0" wire:model="imageForm.prices.{{ $key }}" class="py-1" placeholder="0.00" /></label>
            @empty
                <p class="text-xs text-zinc-500">Tick a size first.</p>
            @endforelse
        </div>
    </x-form.field>

    <details class="rounded-md border border-zinc-200 p-3 dark:border-zinc-800" @if ($errors->has('imageForm.input_price') || $errors->has('imageForm.output_price') || $errors->has('imageForm.image_input_price')) open @endif>
        <summary class="cursor-pointer font-medium select-none">Advanced</summary>
        <div class="mt-3 grid gap-4 sm:grid-cols-3">
            <x-form.field label="Text input, per million tokens" name="imageForm.input_price">
                <x-form.input type="number" step="0.0001" min="0" wire:model="imageForm.input_price" id="imageForm.input_price" />
            </x-form.field>
            <x-form.field label="Image input, per million tokens" name="imageForm.image_input_price">
                <x-form.input type="number" step="0.0001" min="0" wire:model="imageForm.image_input_price" id="imageForm.image_input_price" />
            </x-form.field>
            <x-form.field label="Output, per million tokens" name="imageForm.output_price">
                <x-form.input type="number" step="0.0001" min="0" wire:model="imageForm.output_price" id="imageForm.output_price" />
            </x-form.field>
        </div>
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">The actual cost of each run comes from the provider's usage figures at these token prices.</p>
    </details>

    <x-form.field label="Notes" name="imageForm.notes">
        <x-form.textarea wire:model="imageForm.notes" id="imageForm.notes" rows="2" />
    </x-form.field>

    <div class="flex gap-2">
        <x-button type="submit" size="sm" wire:loading.attr="disabled">Save model</x-button>
        <x-button type="button" size="sm" variant="ghost" wire:click="closeAll">Cancel</x-button>
    </div>
</form>
