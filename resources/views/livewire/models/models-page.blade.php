<div class="max-w-5xl space-y-8">
    <p class="max-w-3xl text-zinc-600 dark:text-zinc-400">
        Every model the app may use and what it costs. Switch a model off and nothing picks it; add a row when a provider releases a new one. Prices are the providers' list prices and feed the cost estimates, so keep them current.
    </p>

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="font-semibold">Image models</h2>
                <p class="text-zinc-600 dark:text-zinc-400">Grouped by the provider that makes them. Each needs a connection with that provider's key.</p>
            </div>
            <x-button variant="secondary" wire:click="addStarters" wire:loading.attr="disabled">{{ $providers->sum(fn ($p) => $p['models']->count()) === 0 ? 'Add the starting models' : 'Add any missing starters' }}</x-button>
        </div>

        @if ($imageOpen === 'new')
            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="mb-3 font-semibold">New image model</h3>
                @include('livewire.models._image-model-form')
            </div>
        @endif

        @forelse ($providers as $provider)
            <div wire:key="provider-{{ $provider['key'] }}" @class(['rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900', 'opacity-60' => ! $provider['enabled']])>
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
                    <div>
                        <h3 class="font-semibold">{{ $provider['name'] }} @unless ($provider['enabled']) <span class="ml-1 text-xs font-normal text-zinc-500">plugin switched off</span> @endunless</h3>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            @if (! $provider['connection'])
                                No connection yet. <a href="{{ route('connections.create', ['plugin' => $provider['key']]) }}" class="underline">Add one</a> with the API key before these models can be used.
                            @elseif (! $provider['connection']->isConnected())
                                The connection "{{ $provider['connection']->name }}" isn't working. <a href="{{ route('connections.show', $provider['connection']) }}" class="underline">Open it</a> and press "Check now".
                            @else
                                Uses the connection "{{ $provider['connection']->name }}".
                            @endif
                        </p>
                    </div>
                    <x-button size="sm" variant="ghost" wire:click="openImage(null, '{{ $provider['key'] }}')">Add a model</x-button>
                </div>
                <table class="w-full text-left">
                    <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-950/40 dark:text-zinc-400">
                        <tr>
                            <th class="px-5 py-2 font-medium">Model</th>
                            <th class="px-3 py-2 font-medium">Takes</th>
                            <th class="px-3 py-2 font-medium">Per image</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($provider['models'] as $model)
                            <tr wire:key="image-model-{{ $model->id }}" @class(['align-top', 'opacity-60' => ! $model->enabled])>
                                <td class="px-5 py-2">
                                    <div class="font-medium">{{ $model->label }} @unless ($model->enabled) <span class="text-xs font-normal text-zinc-500">off</span> @endunless</div>
                                    <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $model->model }}</div>
                                    @if ($model->notes) <div class="mt-1 max-w-md text-xs text-zinc-500 dark:text-zinc-400">{{ $model->notes }}</div> @endif
                                </td>
                                <td class="max-w-xs px-3 py-2 text-xs text-zinc-600 dark:text-zinc-400">{{ $model->summary() }}</td>
                                <td class="px-3 py-2 text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                    @forelse ($model->prices as $key => $price)
                                        <div>{{ $key }}: ${{ number_format($price, $price < 0.01 ? 4 : 3) }}</div>
                                    @empty — @endforelse
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <x-button size="sm" variant="ghost" wire:click="openImage({{ $model->id }})">Edit</x-button>
                                    <x-button size="sm" variant="ghost" wire:click="toggleImage({{ $model->id }})">{{ $model->enabled ? 'Switch off' : 'Switch on' }}</x-button>
                                    @if ($removing === $model->id)
                                        <x-button size="sm" variant="danger" wire:click="remove({{ $model->id }})">Yes, remove</x-button>
                                        <x-button size="sm" variant="ghost" wire:click="keep">Keep</x-button>
                                    @else
                                        <x-button size="sm" variant="ghost" wire:click="askRemove({{ $model->id }})">Remove</x-button>
                                    @endif
                                </td>
                            </tr>
                            @if ($imageOpen === (string) $model->id)
                                <tr wire:key="image-model-form-{{ $model->id }}" class="bg-zinc-50 dark:bg-zinc-950/40"><td colspan="4" class="px-5 py-4">@include('livewire.models._image-model-form')</td></tr>
                            @endif
                        @empty
                            <tr><td colspan="4" class="px-5 py-4 text-zinc-500 dark:text-zinc-400">No models yet. "Add the starting models" fills in {{ $provider['name'] }}'s.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @empty
            <p class="rounded-lg border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">No image provider plugin is installed.</p>
        @endforelse
    </section>

    <section class="space-y-3">
        <div>
            <h2 class="font-semibold">Text models</h2>
            <p class="text-zinc-600 dark:text-zinc-400">The models that read the brief, plan, write the prompts and copy, and review. Which one each brand uses is in its Settings → AI models. Prices are US dollars per million tokens.</p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <table class="w-full text-left">
                <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-950/40 dark:text-zinc-400">
                    <tr>
                        <th class="px-5 py-2 font-medium">Model</th>
                        <th class="px-3 py-2 font-medium">Provider</th>
                        <th class="px-3 py-2 font-medium">In</th>
                        <th class="px-3 py-2 font-medium">Out</th>
                        <th class="px-3 py-2 font-medium">Cache write</th>
                        <th class="px-3 py-2 font-medium">Cache read</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($llmModels as $model)
                        <tr wire:key="llm-model-{{ $model->id }}" @class(['align-top', 'opacity-60' => ! $model->enabled])>
                            <td class="px-5 py-2">
                                <div class="font-medium">{{ $model->label }} @unless ($model->enabled) <span class="text-xs font-normal text-zinc-500">off</span> @endunless</div>
                                <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $model->model }}</div>
                            </td>
                            <td class="px-3 py-2 text-xs text-zinc-600 dark:text-zinc-400">{{ $llmProviders[$model->provider] ?? $model->provider }}</td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400">${{ $model->input_price }}</td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400">${{ $model->output_price }}</td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400">${{ $model->cache_write_price }}</td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400">${{ $model->cache_read_price }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <x-button size="sm" variant="ghost" wire:click="openLlm({{ $model->id }})">Edit</x-button>
                                <x-button size="sm" variant="ghost" wire:click="toggleLlm({{ $model->id }})">{{ $model->enabled ? 'Switch off' : 'Switch on' }}</x-button>
                            </td>
                        </tr>
                        @if ($llmOpen === (string) $model->id)
                            <tr wire:key="llm-model-form-{{ $model->id }}" class="bg-zinc-50 dark:bg-zinc-950/40"><td colspan="7" class="px-5 py-4">@include('livewire.models._llm-model-form')</td></tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
