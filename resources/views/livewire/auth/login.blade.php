<div class="space-y-6">
    <div>
        <h1 class="text-lg font-semibold">Sign in to {{ config('app.name') }}</h1>
    </div>

    @if (session('status'))
        <p class="rounded-md bg-green-50 px-3 py-2 text-green-800 dark:bg-green-950/50 dark:text-green-300">{{ session('status') }}</p>
    @endif

    @error('google')
        <p class="rounded-md bg-red-50 px-3 py-2 text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $message }}</p>
    @enderror

    <a href="{{ route('google.redirect') }}"
       class="flex w-full items-center justify-center gap-2 rounded-md border border-zinc-300 bg-white px-3 py-2 font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
        <x-google-logo class="size-4" />
        Continue with Google
    </a>

    <div class="flex items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
        <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
        or, if you were given a password
        <span class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></span>
    </div>

    <form wire:submit="login" class="space-y-4">
        <x-form.field label="Email" name="email">
            <x-form.input type="email" wire:model="email" id="email" autocomplete="email" required />
        </x-form.field>

        <x-form.field label="Password" name="password">
            <x-form.input type="password" wire:model="password" id="password" autocomplete="current-password" required />
        </x-form.field>

        <div class="flex items-center justify-between">
            <a href="{{ route('password.request') }}" class="text-zinc-600 underline hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">Forgot your password?</a>
            <x-button type="submit" wire:loading.attr="disabled">Sign in</x-button>
        </div>
    </form>
</div>
