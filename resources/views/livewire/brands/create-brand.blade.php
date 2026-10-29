<div class="max-w-xl">
    <form wire:submit="save" class="space-y-4 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <p class="text-zinc-600 dark:text-zinc-400">A brand is one store's marketing: its own catalogue, brand kit, prompts, templates and settings.</p>

        <x-form.field label="Name" name="name" help="Such as “BioSteel” or “Canadian Protein”.">
            <x-form.input wire:model="name" id="name" required autofocus />
        </x-form.field>

        <x-form.field label="Colour" name="color" help="The dot beside the name in the switcher.">
            <input type="color" wire:model="color" id="color" class="h-9 w-16 cursor-pointer rounded-md border border-zinc-300 bg-white p-0.5 dark:border-zinc-700 dark:bg-zinc-800" />
        </x-form.field>

        <x-button type="submit" wire:loading.attr="disabled">Create brand</x-button>
    </form>
</div>
