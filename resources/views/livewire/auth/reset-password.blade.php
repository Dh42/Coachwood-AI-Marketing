<div class="space-y-5">
    <h1 class="text-lg font-semibold">Choose a new password</h1>

    <form wire:submit="resetPassword" class="space-y-4">
        <x-form.field label="Email" name="email">
            <x-form.input type="email" wire:model="email" id="email" autocomplete="email" required />
        </x-form.field>

        <x-form.field label="New password" name="password" help="At least 12 characters.">
            <x-form.input type="password" wire:model="password" id="password" autocomplete="new-password" required />
        </x-form.field>

        <x-form.field label="Confirm new password" name="password_confirmation">
            <x-form.input type="password" wire:model="password_confirmation" id="password_confirmation" autocomplete="new-password" required />
        </x-form.field>

        <x-button type="submit" class="w-full" wire:loading.attr="disabled">Change password</x-button>
    </form>
</div>
