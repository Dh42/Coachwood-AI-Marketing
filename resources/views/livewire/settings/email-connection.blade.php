<section class="max-w-3xl">
    <h2 class="mb-2 font-semibold">Sending email</h2>
    <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        @error('email_connection')
            <p class="mb-3 rounded-md bg-red-50 px-3 py-2 text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $message }}</p>
        @enderror

        @if ($mailer !== 'gmail_api')
            <p class="mb-3 rounded-md bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
                This server isn't set to send through Gmail (<code>MAIL_MAILER={{ $mailer }}</code>), so emails won't go through the account below.
            </p>
        @endif

        <div class="flex items-start justify-between gap-4">
            <div>
                @if ($connection)
                    <p>Invitations, password resets and alerts are sent from <strong>{{ $connection->email }}</strong>.</p>
                    <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">
                        Connected {{ $connection->created_at->diffForHumans() }}{{ $connection->connectedBy ? ' by '.$connection->connectedBy->name : '' }}.
                    </p>
                @else
                    <p>No account connected yet, so invitations and password resets can't be sent.</p>
                    <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">Connect the Google account the emails should come from. It only gets permission to send.</p>
                @endif
            </div>
            <div class="flex shrink-0 gap-2">
                @if ($connection)
                    <x-button variant="secondary" wire:click="sendTest" wire:loading.attr="disabled">Send a test</x-button>
                @endif
                <a href="{{ route('settings.email.connect') }}"
                   class="inline-flex items-center gap-1.5 rounded-md border border-zinc-300 bg-white px-3 py-2 font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                    <x-google-logo class="size-4" />
                    {{ $connection ? 'Change account' : 'Connect Gmail' }}
                </a>
            </div>
        </div>
    </div>
</section>
