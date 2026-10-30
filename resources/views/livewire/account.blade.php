<div class="max-w-xl space-y-8">
    <section class="space-y-4">
        <h2 class="text-base font-semibold">Profile</h2>

        <dl class="grid grid-cols-3 gap-y-2">
            <dt class="text-zinc-500 dark:text-zinc-400">Email</dt>
            <dd class="col-span-2">{{ $user->email }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Signs in with</dt>
            <dd class="col-span-2">{{ $user->google_id ? 'Google' : 'Email and password' }}</dd>
            <dt class="text-zinc-500 dark:text-zinc-400">Role</dt>
            <dd class="col-span-2">{{ $user->platformRole()?->label() ?? 'No access yet' }}</dd>
        </dl>

        <form wire:submit="saveName" class="flex items-end gap-3">
            <x-form.field label="Name" name="name" class="flex-1">
                <x-form.input wire:model="name" id="name" autocomplete="name" />
            </x-form.field>
            <x-button type="submit" variant="secondary">Save</x-button>
        </form>
    </section>

    <section id="notices" class="space-y-4">
        <h2 class="text-base font-semibold">Notices</h2>
        <p class="text-zinc-600 dark:text-zinc-400">What happens to your requests shows under the bell at the top of every page. Choose what you're emailed about as well.</p>
        <livewire:settings.settings-form :scope="\App\Settings\SettingScope::User" :scope-id="$user->id" section="Notifications" :titles="false" />
    </section>

    @if ($user->usesPassword())
        <section class="space-y-4">
            <h2 class="text-base font-semibold">Password</h2>

            <form wire:submit="changePassword" class="space-y-4">
                <x-form.field label="Current password" name="current_password">
                    <x-form.input type="password" wire:model="current_password" id="current_password" autocomplete="current-password" />
                </x-form.field>
                <x-form.field label="New password" name="password" help="At least 12 characters.">
                    <x-form.input type="password" wire:model="password" id="password" autocomplete="new-password" />
                </x-form.field>
                <x-form.field label="Confirm new password" name="password_confirmation">
                    <x-form.input type="password" wire:model="password_confirmation" id="password_confirmation" autocomplete="new-password" />
                </x-form.field>
                <x-button type="submit" variant="secondary">Change password</x-button>
            </form>
        </section>
    @endif
</div>
