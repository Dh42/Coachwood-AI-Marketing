<form wire:submit="savePlatform" class="max-w-2xl space-y-4">
    <x-form.field label="Name" name="platformForm.name">
        <x-form.input wire:model="platformForm.name" id="platformForm.name" class="max-w-sm" />
    </x-form.field>
    <x-form.field label="A note for the prompts" name="platformForm.prompt_note" help="A line the planner adds when it writes for this platform, such as “TikTok: native, unpolished feel.”">
        <x-form.textarea wire:model="platformForm.prompt_note" id="platformForm.prompt_note" rows="2" />
    </x-form.field>
    <div class="flex gap-2">
        <x-button type="submit" size="sm" wire:loading.attr="disabled">Save platform</x-button>
        <x-button type="button" size="sm" variant="ghost" wire:click="closeAll">Cancel</x-button>
    </div>
</form>
