{{-- Under one clean image: the "Make a change" control and every changed version made from it, each with its score beside the one it came from. Expects $output. --}}
@php($own = $changeProblems[$output->id] ?? null)
@if ($own !== null)
    @if ($changing === $output->id)
        <form wire:submit="makeChange" class="mt-1 space-y-1">
            <x-form.textarea wire:model="instruction" rows="2" placeholder="What to change, in a few words: darker background, no shaker, more space at the top…" class="text-xs" />
            @error('instruction') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            <div class="flex gap-1">
                <x-button type="submit" size="sm" wire:loading.attr="disabled">Make it</x-button>
                <x-button type="button" size="sm" variant="ghost" wire:click="keepImage">Keep as it is</x-button>
            </div>
        </form>
    @elseif ($own['problem'] === null)
        <div class="mt-1"><x-button size="sm" variant="ghost" wire:click="askChange({{ $output->id }})">Make a change</x-button></div>
    @elseif ($own['fresh'])
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $own['problem'] }} <a href="{{ $own['fresh'] }}" wire:navigate class="underline">Start a fresh request</a></p>
    @endif
@endif

@foreach ($request->generations->where('source_output_id', $output->id) as $change)
    @php($version = $change->outputs->first())
    <div wire:key="change-{{ $change->id }}" class="mt-2 border-l-2 border-zinc-200 pl-2 dark:border-zinc-700">
        <p class="text-xs text-zinc-600 dark:text-zinc-400">“{{ $change->instruction }}”{{ $change->user ? ' · '.$change->user->name : '' }}</p>
        @if ($version)
            @if ($version->isRemoved())
                <div class="mt-1 flex aspect-square items-center justify-center rounded-md border border-dashed border-zinc-300 px-2 text-center text-xs text-zinc-400 dark:border-zinc-700">Rejected; the file was deleted after {{ $rejectsDays === 1 ? 'a day' : "{$rejectsDays} days" }}</div>
            @else
                <a href="{{ $version->url() }}" target="_blank" class="mt-1 block overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800" title="Open at full size">
                    <img src="{{ $version->thumbUrl() }}" alt="Changed image: {{ $change->instruction }}" class="w-full object-contain" loading="lazy" />
                </a>
            @endif
            @if ($version->score !== null)
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                    <span @class(['font-medium', 'text-green-700 dark:text-green-400' => $version->score >= 70, 'text-amber-700 dark:text-amber-400' => $version->score < 70])>{{ $version->score }}</span>
                    @if ($output->score !== null)
                        @if ($version->score < $output->score) <span class="text-amber-700 dark:text-amber-400">down from {{ $output->score }}</span> @else (was {{ $output->score }}) @endif
                    @endif
                    @if ($version->isShortlisted()) · <span class="text-green-700 dark:text-green-400">Shortlisted</span> @endif
                    @if ($version->reason) · {{ $version->reason }} @endif
                </p>
            @elseif ($change->isBusy())
                <p class="mt-0.5 text-xs text-zinc-400">Reviewing…</p>
            @elseif ($version->review && $version->review->status === 'failed')
                <p class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">Not reviewed: {{ $version->review->error }}</p>
            @endif
            @if ($version->ads->isNotEmpty())
                <div class="mt-1 grid grid-cols-3 gap-1">
                    @foreach ($version->ads as $ad)
                        <figure wire:key="version-ad-{{ $ad->id }}" @class(['opacity-60' => $ad->isRejected()])>
                            <a href="{{ $ad->url() }}" target="_blank" class="block overflow-hidden rounded bg-zinc-100 dark:bg-zinc-800" title="{{ $ad->placement?->label() }} · {{ $ad->isRejected() ? $ad->reason : ($ad->score !== null ? 'Scored '.$ad->score : 'Reviewing…') }}">
                                <img src="{{ $ad->thumbUrl() }}" alt="Finished ad from the changed image" class="w-full object-contain" loading="lazy" />
                            </a>
                            <figcaption class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $ad->score !== null ? $ad->score : '…' }}{{ $ad->isShortlisted() ? ' · Shortlisted' : '' }} · <a href="{{ $ad->url() }}?download=1" class="underline">Download</a></figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif
            @include('livewire.requests._changes', ['output' => $version])
        @elseif ($change->isBusy())
            <p class="mt-0.5 flex items-center gap-1 text-xs text-zinc-400"><svg class="size-3 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg> Changing…</p>
        @else
            <p class="mt-0.5 text-xs text-red-700 dark:text-red-400">Failed: {{ $change->error }}</p>
        @endif
    </div>
@endforeach
