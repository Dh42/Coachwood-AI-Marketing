<form wire:submit="save" class="max-w-3xl space-y-6">
    @include('livewire.requests._form')

    <x-form.field label="Reference images" name="uploads" help="Optional. Images that show the look you want, or a pack shot to use. JPG, PNG or WebP.">
        <input type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp" class="block text-sm" />
        <div wire:loading wire:target="uploads" class="mt-1 text-xs text-zinc-500">Uploading…</div>
        @if ($uploads !== [])
            <ul class="mt-2 space-y-1">
                @foreach ($uploads as $i => $file)
                    <li wire:key="upload-{{ $i }}" class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="truncate">{{ $file->getClientOriginalName() }}</span>
                        <x-form.select wire:model="uploadKinds.{{ $i }}" class="w-64 py-1">
                            @foreach (\App\Models\RequestReference::KINDS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-form.select>
                        <x-button type="button" size="sm" variant="ghost" wire:click="dropUpload({{ $i }})">Remove</x-button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-form.field>

    <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="form.public" class="rounded border-zinc-300 dark:border-zinc-700"> Public: the whole team can see this request and its results. Untick to keep it to you and admins.</label>

    <div class="flex items-center gap-3">
        <x-button type="submit" class="shrink-0 whitespace-nowrap" wire:loading.attr="disabled">Plan it</x-button>
        <span class="text-xs text-zinc-500 dark:text-zinc-400">Nothing is generated yet: the planner reads the brief, may ask a question or two, then shows the plan and its cost to confirm.</span>
    </div>
</form>
