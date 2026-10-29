<div class="max-w-5xl space-y-8">
    @error('oauth')
        <p class="rounded-md bg-red-50 px-3 py-2 text-red-700 dark:bg-red-950/50 dark:text-red-300">{{ $message }}</p>
    @enderror

    <section>
        <div class="flex items-end justify-between gap-4">
            <div>
                <h2 class="font-semibold">Connected accounts</h2>
                <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">Each account is connected once. A brand's own connections, such as its Shopify store, belong to it; a provider account the whole company pays for is shared.</p>
            </div>
            <a href="{{ route('connections.create') }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-zinc-900 px-3 py-2 font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <x-heroicon-m-plus class="size-4" /> Add connection
            </a>
        </div>

        @if ($brands->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-form.select wire:model.live="brand" class="w-56" aria-label="Brand">
                    <option value="">Every brand</option>
                    @foreach ($brands as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                    <option value="shared">Shared</option>
                </x-form.select>
            </div>
        @endif

        @if ($connections->isEmpty())
            <p class="mt-4 rounded-lg border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                Nothing connected yet. Start with each brand's Shopify store and the AI providers.
            </p>
        @endif

        @foreach ($groups as $group)
        <h3 class="mt-5 text-sm font-semibold text-zinc-500 dark:text-zinc-400">{{ $group['title'] }}</h3>
        <ul class="mt-2 grid grid-cols-2 gap-3">
            @foreach ($group['list'] as $connection)
                @php($plugin = $connection->plugin())
                @php($uses = $usesOf($connection))
                <li wire:key="connection-{{ $connection->id }}">
                    <a href="{{ route('connections.show', $connection) }}" class="block rounded-lg border border-zinc-200 bg-white p-4 hover:border-zinc-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-600">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ $connection->name }}</div>
                                <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $plugin?->name() ?? $connection->plugin }} · {{ $plugin?->accountLabel($connection) }}</div>
                            </div>
                            <x-connection-status :connection="$connection" />
                        </div>
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            @if (! $registry->isEnabled($connection->plugin))
                                Its plugin is switched off.
                            @elseif ($uses === [])
                                Not used by anything yet.
                            @else
                                Used by {{ implode(', ', $uses) }}.
                            @endif
                        </p>
                    </a>
                </li>
            @endforeach
        </ul>
        @endforeach
    </section>

    <section>
        <h2 class="font-semibold">Plugins</h2>
        <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">The services the app can connect to. Switching one off stops everything using its connections straight away, without deleting anything.</p>

        <ul class="mt-3 divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
            @foreach ($plugins as $plugin)
                @php($on = $registry->isEnabled($plugin->key()))
                <li wire:key="plugin-{{ $plugin->key() }}" class="flex items-center justify-between gap-4 px-4 py-3">
                    <div>
                        <div class="font-medium">{{ $plugin->name() }}</div>
                        <div class="text-zinc-500 dark:text-zinc-400">{{ $plugin->description() }}</div>
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $plugin->name() }} plugin"
                            wire:click="setPlugin('{{ $plugin->key() }}', {{ $on ? 'false' : 'true' }})"
                            @class(['relative h-5 w-9 shrink-0 rounded-full transition', 'bg-zinc-900 dark:bg-white' => $on, 'bg-zinc-300 dark:bg-zinc-700' => ! $on])>
                        <span @class(['absolute top-0.5 left-0.5 size-4 rounded-full bg-white transition dark:bg-zinc-900', 'translate-x-4' => $on])></span>
                    </button>
                </li>
            @endforeach
        </ul>
    </section>
</div>
