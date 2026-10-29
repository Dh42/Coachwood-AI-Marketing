<div class="max-w-3xl space-y-8">
    @if (session('status'))
        <p class="rounded-md bg-green-50 px-3 py-2 text-green-800 dark:bg-green-950/50 dark:text-green-300">{{ session('status') }}</p>
    @endif
    @error('oauth')
        <p class="rounded-md bg-red-50 px-3 py-2 text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $message }}</p>
    @enderror

    @if ($notice && $connection->isConnected())
        <p class="rounded-md bg-sky-50 px-3 py-2 text-sky-900 dark:bg-sky-950/50 dark:text-sky-200">
            {{ $notice['text'] }}
            @if ($notice['link'] ?? null)
                <a href="{{ $notice['link'] }}" target="_blank" rel="noopener" class="underline">{{ $notice['link_text'] ?? 'Open' }}</a>.
            @endif
        </p>
    @endif

    @if ($plugin && ($attention = $plugin->attentionNeeded($connection)))
        <p class="rounded-md bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">{{ $attention }}</p>
    @endif

    @if ($plugin && $plugin->needsSetup($connection) && $plugin->connectComponent())
        <section class="rounded-lg border border-amber-300 bg-white p-5 dark:border-amber-800 dark:bg-zinc-900">
            <h2 class="font-semibold">Set this connection up again</h2>
            <p class="mt-0.5 mb-4 text-zinc-500 dark:text-zinc-400">This account needs one more set-up step before it can sign in.</p>
            <livewire:dynamic-component :is="$plugin->connectComponent()" :connection="$connection" :key="'repair-'.$connection->id" />
        </section>
    @endif

    @if (! $plugin)
        <p class="rounded-md bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">The plugin for this connection ({{ $connection->plugin }}) isn't installed any more.</p>
    @else
        <section class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="font-semibold">{{ $plugin->name() }}</h2>
                        <x-connection-status :connection="$connection" />
                    </div>
                    <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">
                        @if ($connection->isConnected())
                            Connected to {{ $plugin->accountLabel($connection) }}.
                        @elseif ($connection->status === \App\Models\Connection::STATUS_ERROR)
                            {{ $connection->last_error }}
                        @else
                            {{ $plugin->connectionMethod() === \App\Plugins\ConnectionMethod::OAuth ? 'Sign in to finish connecting.' : 'Not checked yet.' }}
                        @endif
                    </p>
                    @if ($connection->checked_at)
                        <p class="mt-0.5 text-xs text-zinc-400">Last checked {{ $connection->checked_at->diffForHumans() }}.</p>
                    @endif
                </div>
                <div class="flex shrink-0 gap-2">
                    @if ($plugin->connectionMethod() === \App\Plugins\ConnectionMethod::OAuth && ! $plugin->needsSetup($connection))
                        <a href="{{ route('connections.authorize', $connection) }}" class="inline-flex items-center rounded-md bg-zinc-900 px-3 py-2 font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                            {{ $connection->isConnected() ? 'Reconnect' : 'Sign in to '.$plugin->name() }}
                        </a>
                    @endif
                    <x-button variant="secondary" wire:click="check" wire:loading.attr="disabled" wire:target="check">Check now</x-button>
                </div>
            </div>
        </section>

        @foreach ($plugin->setupDetails($connection) as $detail)
            <section class="space-y-1.5 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="font-semibold">{{ $detail['label'] }}</h2>
                <code x-data class="block cursor-pointer rounded bg-zinc-100 px-2 py-1.5 font-mono text-xs break-all dark:bg-zinc-800" title="Copy"
                    @click="navigator.clipboard.writeText(@js($detail['value'])); $dispatch('saved', { message: 'Copied.' })">{{ $detail['value'] }}</code>
                @if ($detail['help'] ?? null)
                    <p class="text-zinc-500 dark:text-zinc-400">{{ $detail['help'] }}</p>
                @endif
            </section>
        @endforeach

        <section>
            <h2 class="font-semibold">Details</h2>
            <form wire:submit="save" class="mt-2 space-y-4 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <x-form.field label="Name" name="name">
                    <x-form.input wire:model="name" id="name" />
                </x-form.field>
                @include('livewire.connections.brand')
                @include('livewire.connections.fields')
                <x-button type="submit" size="sm">Save</x-button>
            </form>
        </section>

    @endif

    <section>
        <h2 class="font-semibold">Used by</h2>
        @if ($uses === [])
            <p class="mt-1 text-zinc-500 dark:text-zinc-400">Nothing uses this connection yet.</p>
        @else
            <ul class="mt-2 list-disc space-y-1 pl-5 text-zinc-600 dark:text-zinc-400">
                @foreach ($uses as $use)
                    <li>{{ $use }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    <section x-data="{ confirming: null }" class="flex flex-wrap gap-2 border-t border-zinc-200 pt-6 dark:border-zinc-800">
        <template x-if="confirming === null">
            <div class="flex gap-2">
                <x-button variant="secondary" @click="confirming = 'disconnect'">Disconnect</x-button>
                <x-button variant="ghost" @click="confirming = 'delete'">Remove connection</x-button>
            </div>
        </template>
        <template x-if="confirming === 'disconnect'">
            <div class="flex items-center gap-2">
                <span>Nothing can use this account until it's connected again. Everything set up on it is kept.</span>
                <x-button variant="danger" wire:click="disconnect" @click="confirming = null">Disconnect</x-button>
                <x-button variant="ghost" @click="confirming = null">Keep connected</x-button>
            </div>
        </template>
        <template x-if="confirming === 'delete'">
            <div class="flex items-center gap-2">
                <span>This deletes the connection and its saved details.</span>
                <x-button variant="danger" wire:click="delete">Remove</x-button>
                <x-button variant="ghost" @click="confirming = null">Keep</x-button>
            </div>
        </template>
    </section>
</div>
