<form wire:submit="savePreset" class="space-y-4">
    <x-form.field label="Name" name="presetForm.name">
        <x-form.input wire:model="presetForm.name" id="presetForm.name" class="max-w-sm" placeholder="Meta full set" />
    </x-form.field>
    <div>
        <p class="font-medium">Placements in the set</p>
        @error('presetForm.placement_ids') <p class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        <div class="mt-2 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($platforms->where('enabled', true) as $platform)
                @if ($platform->placements->where('enabled', true)->isNotEmpty())
                    <div>
                        <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ $platform->name }}</p>
                        @foreach ($platform->placements->where('enabled', true) as $placement)
                            <label class="mt-1 flex items-center gap-2"><input type="checkbox" value="{{ $placement->id }}" wire:model="presetForm.placement_ids" class="size-4 rounded border-zinc-300 dark:border-zinc-600" /> {{ $placement->name }} <span class="text-xs text-zinc-500">{{ $placement->ratio }}</span></label>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    </div>
    <div class="flex gap-2">
        <x-button type="submit" size="sm" wire:loading.attr="disabled">Save set</x-button>
        <x-button type="button" size="sm" variant="ghost" wire:click="closeAll">Cancel</x-button>
    </div>
</form>
