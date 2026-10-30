@php
    $err = fn (string $path) => $errors->first($path);
    $inputClass = 'py-1';
@endphp
<div class="max-w-5xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-3xl text-zinc-600 dark:text-zinc-400">
            Every platform and placement the team can ask for: the pixel size, the aspect ratio it's generated at, the safe zone the platform's own buttons and captions cover, the file rules, how long a video may be, and how long each piece of copy may be. When a platform changes a spec, change the row here.
        </p>
        <div class="flex gap-2">
            <x-button variant="secondary" wire:click="addStarters" wire:loading.attr="disabled">{{ $platforms->isEmpty() ? 'Add the starting platforms' : 'Add any missing starters' }}</x-button>
            <x-button wire:click="openPlatform">Add a platform</x-button>
        </div>
    </div>

    @if ($unchecked > 0)
        <p class="rounded-md bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
            {{ $unchecked }} {{ Str::plural('platform', $unchecked) }} {{ $unchecked === 1 ? 'has' : 'have' }} specs written from general knowledge, not checked against the platform's current docs. Open each platform's ad specs page, fix any row that differs, then press "Mark as checked".
        </p>
    @endif

    @if ($platformOpen === 'new')
        <section class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="mb-3 font-semibold">New platform</h2>
            @include('livewire.platforms._platform-form')
        </section>
    @endif

    @forelse ($platforms as $platform)
        <section wire:key="platform-{{ $platform->id }}" @class(['rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900', 'opacity-60' => ! $platform->enabled])>
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
                <div class="min-w-0">
                    <h2 class="font-semibold">{{ $platform->name }} @unless ($platform->enabled) <span class="ml-1 text-xs font-normal text-zinc-500">switched off</span> @endunless</h2>
                    @if ($platform->prompt_note)
                        <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">{{ $platform->prompt_note }}</p>
                    @endif
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($platform->isChecked())
                            Checked against the platform's docs by {{ $platform->verifier?->name ?? 'someone' }} on {{ $platform->verified_at->timezone($tz)->format('M j, Y') }}.
                            <button type="button" wire:click="markChecked({{ $platform->id }}, false)" class="underline">Needs checking again</button>
                        @else
                            Not checked against the platform's docs yet.
                            <button type="button" wire:click="markChecked({{ $platform->id }}, true)" class="underline">Mark as checked</button>
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-1">
                    <x-button size="sm" variant="ghost" wire:click="openPlacement({{ $platform->id }})">Add a placement</x-button>
                    <x-button size="sm" variant="ghost" wire:click="openPlatform({{ $platform->id }})">Edit</x-button>
                    <x-button size="sm" variant="ghost" wire:click="togglePlatform({{ $platform->id }})">{{ $platform->enabled ? 'Switch off' : 'Switch on' }}</x-button>
                    @if ($removing === "platform-{$platform->id}")
                        <x-button size="sm" variant="danger" wire:click="remove('platform-{{ $platform->id }}')">Yes, remove it and its placements</x-button>
                        <x-button size="sm" variant="ghost" wire:click="keep">Keep</x-button>
                    @else
                        <x-button size="sm" variant="ghost" wire:click="askRemove('platform-{{ $platform->id }}')">Remove</x-button>
                    @endif
                </div>
            </div>

            @if ($platformOpen === (string) $platform->id)
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    @include('livewire.platforms._platform-form')
                </div>
            @endif

            <table class="w-full text-left">
                <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-950/40 dark:text-zinc-400">
                    <tr>
                        <th class="px-5 py-2 font-medium">Placement</th>
                        <th class="px-3 py-2 font-medium">Size</th>
                        <th class="px-3 py-2 font-medium">Takes</th>
                        <th class="px-3 py-2 font-medium">Safe zone</th>
                        <th class="px-3 py-2 font-medium">Copy</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($platform->placements as $placement)
                        <tr wire:key="placement-{{ $placement->id }}" @class(['align-top', 'opacity-60' => ! $placement->enabled])>
                            <td class="px-5 py-2">
                                <div class="font-medium">{{ $placement->name }} @unless ($placement->enabled) <span class="text-xs font-normal text-zinc-500">off</span> @endunless</div>
                                @if ($placement->notes) <div class="max-w-md text-xs text-zinc-500 dark:text-zinc-400">{{ $placement->notes }}</div> @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $placement->sizeLabel() }} <span class="text-xs text-zinc-500">{{ $placement->ratio }}</span></td>
                            <td class="px-3 py-2 text-xs text-zinc-600 dark:text-zinc-400">
                                @if ($placement->takesImages()) <div>Image: {{ strtoupper(implode(', ', $placement->image_types)) }}{{ $placement->max_image_kb ? ', up to '.($placement->max_image_kb >= 1024 ? round($placement->max_image_kb / 1024, 1).' MB' : $placement->max_image_kb.' KB') : '' }}</div> @endif
                                @if ($placement->takesVideo()) <div>Video: {{ strtoupper(implode(', ', $placement->video_types)) }}{{ $placement->max_video_mb ? ', up to '.$placement->max_video_mb.' MB' : '' }}@if ($placement->video_min_seconds || $placement->video_max_seconds), {{ $placement->video_min_seconds ?? '?' }}–{{ $placement->video_max_seconds ?? '?' }} s @endif</div> @endif
                            </td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                @if ($placement->hasSafeZone()) ↑{{ $placement->safe_top }} ↓{{ $placement->safe_bottom }} ←{{ $placement->safe_left }} →{{ $placement->safe_right }} @else — @endif
                            </td>
                            <td class="px-3 py-2 text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                @forelse ($placement->copyLimits() as $field => $limit)
                                    <div>{{ \App\Platforms\CopyField::from($field)->label() }}: {{ $limit }}</div>
                                @empty — @endforelse
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <x-button size="sm" variant="ghost" wire:click="openPlacement({{ $platform->id }}, {{ $placement->id }})">Edit</x-button>
                                <x-button size="sm" variant="ghost" wire:click="togglePlacement({{ $placement->id }})">{{ $placement->enabled ? 'Switch off' : 'Switch on' }}</x-button>
                                @if ($removing === "placement-{$placement->id}")
                                    <x-button size="sm" variant="danger" wire:click="remove('placement-{{ $placement->id }}')">Yes, remove</x-button>
                                    <x-button size="sm" variant="ghost" wire:click="keep">Keep</x-button>
                                @else
                                    <x-button size="sm" variant="ghost" wire:click="askRemove('placement-{{ $placement->id }}')">Remove</x-button>
                                @endif
                            </td>
                        </tr>
                        @if ($placementOpen === (string) $placement->id)
                            <tr wire:key="placement-form-{{ $placement->id }}" class="bg-zinc-50 dark:bg-zinc-950/40"><td colspan="6" class="px-5 py-4">@include('livewire.platforms._placement-form')</td></tr>
                        @endif
                    @empty
                        <tr><td colspan="6" class="px-5 py-4 text-zinc-500 dark:text-zinc-400">No placements yet.</td></tr>
                    @endforelse
                    @if ($placementOpen === "new-{$platform->id}")
                        <tr wire:key="placement-form-new-{{ $platform->id }}" class="bg-zinc-50 dark:bg-zinc-950/40"><td colspan="6" class="px-5 py-4"><h3 class="mb-3 font-semibold">New placement on {{ $platform->name }}</h3>@include('livewire.platforms._placement-form')</td></tr>
                    @endif
                </tbody>
            </table>
        </section>
    @empty
        <p class="rounded-lg border border-dashed border-zinc-300 p-6 text-center text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">No platforms yet. Press "Add the starting platforms" for the common ones, then check each against its docs.</p>
    @endforelse

    <section>
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h2 class="font-semibold">Sets</h2>
                <p class="text-zinc-600 dark:text-zinc-400">Placements the team ticks as one on a request, such as every Meta placement or every vertical video.</p>
            </div>
            <x-button size="sm" variant="secondary" wire:click="openPreset">Add a set</x-button>
        </div>
        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
            @if ($presetOpen === 'new')
                <div class="p-5">@include('livewire.platforms._preset-form')</div>
            @endif
            @forelse ($presets as $preset)
                <div wire:key="preset-{{ $preset->id }}" class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="font-medium">{{ $preset->name }}</h3>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $preset->placements()->map->label()->implode(', ') ?: 'Nothing in it that can be picked.' }}</p>
                        </div>
                        <div class="flex gap-1">
                            <x-button size="sm" variant="ghost" wire:click="openPreset({{ $preset->id }})">Edit</x-button>
                            @if ($removing === "preset-{$preset->id}")
                                <x-button size="sm" variant="danger" wire:click="remove('preset-{{ $preset->id }}')">Yes, remove</x-button>
                                <x-button size="sm" variant="ghost" wire:click="keep">Keep</x-button>
                            @else
                                <x-button size="sm" variant="ghost" wire:click="askRemove('preset-{{ $preset->id }}')">Remove</x-button>
                            @endif
                        </div>
                    </div>
                    @if ($presetOpen === (string) $preset->id)
                        <div class="mt-4">@include('livewire.platforms._preset-form')</div>
                    @endif
                </div>
            @empty
                @if ($presetOpen !== 'new')
                    <p class="p-5 text-zinc-500 dark:text-zinc-400">No sets yet.</p>
                @endif
            @endforelse
        </div>
    </section>
</div>
