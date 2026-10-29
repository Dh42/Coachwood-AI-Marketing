<div class="space-y-5">
    <div>
        <h1 class="text-lg font-semibold">Reset your password</h1>
        <p class="mt-1 text-zinc-600 dark:text-zinc-400">For accounts that sign in with a password. Company accounts use Google instead.</p>
    </div>

    @if ($sent)
        <p class="rounded-md bg-green-50 px-3 py-2 text-green-800 dark:bg-green-950/50 dark:text-green-300">
            If that email has a password account, a reset link is on its way. Check your inbox.
        </p>
    @else
        <form wire:submit="send" class="space-y-4">
            <x-form.field label="Email" name="email">
                <x-form.input type="email" wire:model="email" id="email" autocomplete="email" required />
            </x-form.field>
            <x-button type="submit" class="w-full" wire:loading.attr="disabled">Send reset link</x-button>
        </form>
    @endif

    <a href="{{ route('login') }}" class="block text-zinc-600 underline hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Back to sign in</a>
</div>
