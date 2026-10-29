<div class="max-w-6xl space-y-4">
    <p class="text-zinc-600 dark:text-zinc-400">A record of sign-ins, role and account changes, settings changes and changes to bot groups, with who did what and when.</p>

    <div data-tour="activity-filters" class="flex flex-wrap items-end gap-3">
        <x-form.field label="Type" name="category" class="w-44">
            <x-form.select wire:model.live="category" id="category">
                <option value="">Everything</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-form.select>
        </x-form.field>
        <x-form.field label="Person" name="person" class="w-52">
            <x-form.select wire:model.live="person" id="person">
                <option value="">Anyone</option>
                @foreach ($people as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </x-form.select>
        </x-form.field>
        <x-form.field label="From" name="from">
            <x-form.input type="date" wire:model.live="from" id="from" />
        </x-form.field>
        <x-form.field label="To" name="to">
            <x-form.input type="date" wire:model.live="to" id="to" />
        </x-form.field>
        <x-form.field label="Search" name="search" class="min-w-48 flex-1">
            <x-form.input type="search" wire:model.live.debounce.300ms="search" id="search" placeholder="Words in the description" />
        </x-form.field>
        @if ($category || $person || $from || $to || $search)
            <x-button variant="ghost" wire:click="clearFilters">Clear</x-button>
        @endif
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
        <table class="w-full text-left">
            <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2 font-medium">When</th>
                    <th class="px-4 py-2 font-medium">Who</th>
                    <th class="px-4 py-2 font-medium">Type</th>
                    <th class="px-4 py-2 font-medium">What happened</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @forelse ($entries as $entry)
                    <tr wire:key="activity-{{ $entry->id }}" x-data="{ open: false }" class="align-top">
                        <td class="px-4 py-2.5 whitespace-nowrap" title="{{ $entry->created_at->timezone($tz)->format('M j, Y g:i:s A T') }}">
                            {{ $entry->created_at->timezone($tz)->format('M j, g:i A') }}
                        </td>
                        <td class="px-4 py-2.5">{{ $entry->causer?->name ?? 'System' }}</td>
                        <td class="px-4 py-2.5 whitespace-nowrap">{{ \App\Livewire\Activity\ActivityLog::categoryLabel($entry->log_name) }}</td>
                        <td class="px-4 py-2.5">
                            <div>{{ $entry->description }}</div>
                            @if ($entry->properties->isNotEmpty())
                                <button type="button" class="mt-1 text-xs text-zinc-500 underline dark:text-zinc-400" @click="open = ! open" x-text="open ? 'Hide details' : 'Details'"></button>
                                <dl x-cloak x-show="open" class="mt-2 grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 rounded-md bg-zinc-50 p-3 text-xs dark:bg-zinc-800/50">
                                    @foreach ($entry->properties as $key => $value)
                                        <dt class="text-zinc-500 dark:text-zinc-400">{{ str_replace('_', ' ', $key) }}</dt>
                                        <dd class="font-mono break-all">{{ is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</dd>
                                    @endforeach
                                </dl>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">Nothing matches these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $entries->links() }}
</div>
