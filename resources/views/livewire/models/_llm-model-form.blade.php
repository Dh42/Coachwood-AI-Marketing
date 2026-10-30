<form wire:submit="saveLlm" class="max-w-3xl space-y-4">
    <x-form.field label="Name" name="llmForm.label">
        <x-form.input wire:model="llmForm.label" id="llmForm.label" class="max-w-sm" />
    </x-form.field>
    <div class="grid gap-4 sm:grid-cols-4">
        <x-form.field label="Input" name="llmForm.input_price">
            <x-form.input type="number" step="0.0001" min="0" wire:model="llmForm.input_price" id="llmForm.input_price" />
        </x-form.field>
        <x-form.field label="Output" name="llmForm.output_price">
            <x-form.input type="number" step="0.0001" min="0" wire:model="llmForm.output_price" id="llmForm.output_price" />
        </x-form.field>
        <x-form.field label="Cache write" name="llmForm.cache_write_price">
            <x-form.input type="number" step="0.0001" min="0" wire:model="llmForm.cache_write_price" id="llmForm.cache_write_price" />
        </x-form.field>
        <x-form.field label="Cache read" name="llmForm.cache_read_price">
            <x-form.input type="number" step="0.0001" min="0" wire:model="llmForm.cache_read_price" id="llmForm.cache_read_price" />
        </x-form.field>
    </div>
    <p class="text-xs text-zinc-500 dark:text-zinc-400">US dollars per million tokens, from the provider's price list.</p>
    <div class="flex gap-2">
        <x-button type="submit" size="sm" wire:loading.attr="disabled">Save model</x-button>
        <x-button type="button" size="sm" variant="ghost" wire:click="closeAll">Cancel</x-button>
    </div>
</form>
