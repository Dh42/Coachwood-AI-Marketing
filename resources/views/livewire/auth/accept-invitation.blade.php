<div class="space-y-5">
    @switch($state)
        @case('invalid')
            <h1 class="text-lg font-semibold">This invitation link doesn't work</h1>
            <p class="text-zinc-600 dark:text-zinc-400">It may have been cancelled or replaced by a newer invitation. Ask the person who invited you to send a new one.</p>
            @break

        @case('accepted')
            <h1 class="text-lg font-semibold">This invitation has already been used</h1>
            <p class="text-zinc-600 dark:text-zinc-400">You can sign in with the account it was used for.</p>
            <a href="{{ route('login') }}" class="font-medium underline">Go to sign in</a>
            @break

        @case('expired')
            <h1 class="text-lg font-semibold">This invitation has expired</h1>
            <p class="text-zinc-600 dark:text-zinc-400">Ask the person who invited you to resend it. The new link will work straight away.</p>
            @break

        @case('wrong-account')
            <h1 class="text-lg font-semibold">This invitation is for someone else</h1>
            <p class="text-zinc-600 dark:text-zinc-400">
                You're signed in as {{ auth()->user()->email }}, but the invitation was sent to {{ $invitation->email }}. Sign out, then open the link again.
            </p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-button type="submit" variant="secondary">Sign out</x-button>
            </form>
            @break

        @case('google')
            <h1 class="text-lg font-semibold">You're invited to {{ config('app.name') }}</h1>
            <p class="text-zinc-600 dark:text-zinc-400">
                You've been invited as <strong>{{ $invitation->role->label() }}</strong>. Sign in with your Google account, <strong>{{ $invitation->email }}</strong>, to accept.
            </p>
            <a href="{{ route('google.redirect') }}"
               class="flex w-full items-center justify-center gap-2 rounded-md border border-zinc-300 bg-white px-3 py-2 font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                <x-google-logo class="size-4" />
                Continue with Google
            </a>
            @break

        @case('sign-in')
            <h1 class="text-lg font-semibold">You're invited to {{ config('app.name') }}</h1>
            <p class="text-zinc-600 dark:text-zinc-400">
                You already have an account. Sign in as <strong>{{ $invitation->email }}</strong> and the new access is added automatically.
            </p>
            <a href="{{ route('login') }}" class="font-medium underline">Go to sign in</a>
            @break

        @case('set-password')
            <div>
                <h1 class="text-lg font-semibold">You're invited to {{ config('app.name') }}</h1>
                <p class="mt-1 text-zinc-600 dark:text-zinc-400">
                    Set up your account for <strong>{{ $invitation->email }}</strong>. You've been invited as <strong>{{ $invitation->role->label() }}</strong>.
                </p>
            </div>

            <form wire:submit="createAccount" class="space-y-4">
                <x-form.field label="Your name" name="name">
                    <x-form.input wire:model="name" id="name" autocomplete="name" required />
                </x-form.field>

                <x-form.field label="Password" name="password" help="At least 12 characters.">
                    <x-form.input type="password" wire:model="password" id="password" autocomplete="new-password" required />
                </x-form.field>

                <x-form.field label="Confirm password" name="password_confirmation">
                    <x-form.input type="password" wire:model="password_confirmation" id="password_confirmation" autocomplete="new-password" required />
                </x-form.field>

                <x-button type="submit" class="w-full" wire:loading.attr="disabled">Create account</x-button>
            </form>
            @break
    @endswitch
</div>
