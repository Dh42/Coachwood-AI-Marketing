<div class="max-w-xl space-y-6">
    @if (! $selected)
        <p class="text-zinc-600 dark:text-zinc-400">Which service do you want to connect?</p>
        <ul class="grid grid-cols-2 gap-3">
            @foreach ($available as $plugin)
                <li>
                    <button type="button" wire:click="choose('{{ $plugin->key() }}')" class="w-full rounded-lg border border-zinc-200 bg-white p-4 text-left hover:border-zinc-400 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-600">
                        <div class="font-medium">{{ $plugin->name() }}</div>
                        <div class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ $plugin->description() }}</div>
                    </button>
                </li>
            @endforeach
        </ul>
    @else
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold">Connect {{ $selected->name() }}</h2>
            <x-button size="sm" variant="ghost" wire:click="choose('')">Choose a different service</x-button>
        </div>

        @if ($selected->connectComponent())
            <livewire:dynamic-component :is="$selected->connectComponent()" :key="'connect-'.$selected->key()" />
        @else
        <form wire:submit="save" class="space-y-4">
            <x-form.field label="Name" name="name" :help="'What people will see, such as “BioSteel '.$selected->name().'”.'">
                <x-form.input wire:model="name" id="name" required />
            </x-form.field>

            @include('livewire.connections.brand')

            @include('livewire.connections.fields', ['plugin' => $selected])

            @error('plugin') <p class="text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

            <x-button type="submit" wire:loading.attr="disabled">
                {{ $selected->connectionMethod() === \App\Plugins\ConnectionMethod::OAuth ? 'Save and sign in to '.$selected->name() : 'Connect' }}
            </x-button>
        </form>
        @endif
    @endif
</div>
