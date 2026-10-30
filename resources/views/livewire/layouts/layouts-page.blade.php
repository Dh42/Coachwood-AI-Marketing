<div class="max-w-5xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-2xl text-zinc-600 dark:text-zinc-400">
            Where the headline, offer, logo, product and button sit on a finished ad, for each shape. The composer uses the shape's default layout, scaled to each placement's exact size and kept out of its safe zone. The logo, fonts and colours come from the brand kit.
        </p>
        @if ($canManage)
            <div class="flex gap-2">
                <x-button variant="secondary" wire:click="addStarters" wire:loading.attr="disabled">{{ $templates->isEmpty() ? 'Add the starting layouts' : 'Add any missing shapes' }}</x-button>
                <x-button wire:click="openForm">Add a layout</x-button>
            </div>
        @endif
    </div>

    @if ($open === 'new')
        <section class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="mb-3 font-semibold">New layout</h2>
            @include('livewire.layouts._form')
        </section>
    @endif

    @forelse ($templates as $ratio => $group)
        <section wire:key="ratio-{{ $ratio }}" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="border-b border-zinc-200 px-5 py-3 font-semibold dark:border-zinc-800">{{ $ratio }}</h2>
            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($group as $template)
                    <div wire:key="template-{{ $template->id }}" class="px-5 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <div class="font-medium">{{ $template->name }} @if ($template->is_default) <span class="ml-1 rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-normal text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">Used for {{ $ratio }}</span> @endif</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $template->summary() }}</div>
                            </div>
                            @if ($canManage)
                                <div class="flex flex-wrap gap-1">
                                    <x-button size="sm" variant="ghost" wire:click="openForm({{ $template->id }})">Edit</x-button>
                                    <x-button size="sm" variant="ghost" wire:click="duplicate({{ $template->id }})">Duplicate</x-button>
                                    @unless ($template->is_default)
                                        <x-button size="sm" variant="ghost" wire:click="makeDefault({{ $template->id }})">Use for {{ $ratio }}</x-button>
                                    @endunless
                                    @if ($removing === $template->id)
                                        <x-button size="sm" variant="danger" wire:click="remove({{ $template->id }})">Yes, remove</x-button>
                                        <x-button size="sm" variant="ghost" wire:click="keep">Keep</x-button>
                                    @else
                                        <x-button size="sm" variant="ghost" wire:click="askRemove({{ $template->id }})">Remove</x-button>
                                    @endif
                                </div>
                            @endif
                        </div>
                        @if ($open === (string) $template->id)
                            <div class="mt-4">@include('livewire.layouts._form')</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <p class="rounded-lg border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            No layouts yet, so the built-in ones are used for every shape. Press "Add the starting layouts" to adjust them.
        </p>
    @endforelse
</div>
