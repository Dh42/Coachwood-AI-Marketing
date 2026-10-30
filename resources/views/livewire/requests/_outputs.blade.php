{{-- The run: each shape's generation with its images as they arrive. --}}
<section class="space-y-4">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="font-semibold">
            @if ($request->status === 'running') Generating… @elseif ($request->status === 'done') The images @else The run @endif
        </h2>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            @php($made = $request->outputs()->where('kind', \App\Models\Output::IMAGE)->count())
            {{ $made }} {{ Str::plural('image', $made) }}
            · spent ${{ \App\Budgets\Budgets::money((float) ($request->status === 'running' ? $request->generations()->sum('cost') : $request->actual_cost)) }}{{ $request->status === 'running' ? ' so far' : '' }}, estimated ${{ \App\Budgets\Budgets::money((float) $request->estimated_cost) }}
        </p>
    </div>

    @if ($request->status === 'failed')
        <p class="rounded-md bg-red-50 px-3 py-2 text-red-800 dark:bg-red-950/40 dark:text-red-300">{{ $request->error }}</p>
    @endif

    @foreach ($request->generations->whereNull('source_output_id') as $generation)
        <div wire:key="generation-{{ $generation->id }}" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-zinc-200 px-4 py-2 dark:border-zinc-800">
                <h3 class="font-medium">{{ $generation->ratio }}{{ $generation->user ? ' again' : '' }} <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $generation->user ? 'by '.$generation->user->name : (collect($rows)->firstWhere('ratio', $generation->ratio)['placements'] ?? [] ? implode(', ', collect($rows)->firstWhere('ratio', $generation->ratio)['placements']) : '') }}</span></h3>
                <span class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $generation->model()?->label ?? $generation->image_model }} · {{ $generation->statusLabel() }}{{ $generation->cost > 0 ? ' · $'.\App\Budgets\Budgets::money($generation->cost) : '' }}
                    @if ($canUpdate && $request->status === 'done' && ! $generation->isBusy())
                        <x-button size="sm" variant="ghost" wire:click="runAgain({{ $generation->id }})" wire:loading.attr="disabled" title="{{ $generation->count }} more {{ Str::plural('image', $generation->count) }} of this shape, with the plan unchanged">Run again</x-button>
                    @endif
                </span>
            </div>
            @if ($generation->error)
                <p class="border-b border-zinc-200 px-4 py-2 text-xs text-red-700 dark:border-zinc-800 dark:text-red-400">{{ $generation->failed }} {{ Str::plural('image', $generation->failed) }} failed: {{ $generation->error }}</p>
            @endif
            @if ($generation->compose_error)
                <p class="border-b border-zinc-200 px-4 py-2 text-xs text-red-700 dark:border-zinc-800 dark:text-red-400">Finished ads: {{ $generation->compose_error }}</p>
            @endif
            @php($images = $generation->outputs)
            @php($rejectedImages = $request->status === 'done' ? $images->where('status', 'rejected') : collect())
            <div class="grid grid-cols-2 gap-2 p-4 sm:grid-cols-4">
                @foreach ($images->reject(fn ($o) => $rejectedImages->contains($o)) as $output)
                    <figure wire:key="output-{{ $output->id }}">
                        <a href="{{ $output->url() }}" target="_blank" class="group relative block overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800" title="Open at full size">
                            <img src="{{ $output->thumbUrl() }}" alt="{{ $generation->ratio }} image {{ $output->position }}" class="w-full object-contain" loading="lazy" />
                            <span class="absolute right-1 bottom-1 rounded bg-black/60 px-1.5 py-0.5 text-xs text-white opacity-0 group-hover:opacity-100">{{ $output->width }} × {{ $output->height }}</span>
                        </a>
                        @include('livewire.requests._verdict', ['output' => $output])
                        @include('livewire.requests._changes', ['output' => $output])
                    </figure>
                @endforeach
                @for ($i = $generation->done + $generation->failed; $i < $generation->count && $generation->status !== 'failed'; $i++)
                    <div wire:key="pending-{{ $generation->id }}-{{ $i }}" class="flex aspect-square items-center justify-center rounded-md border border-dashed border-zinc-300 text-xs text-zinc-400 dark:border-zinc-700">…</div>
                @endfor
            </div>
            @if ($rejectedImages->isNotEmpty())
                <details class="border-t border-zinc-200 px-4 py-2 dark:border-zinc-800">
                    <summary class="cursor-pointer text-xs text-zinc-500 select-none dark:text-zinc-400">Rejected: {{ $rejectedImages->count() }} {{ Str::plural('image', $rejectedImages->count()) }}, kept {{ $rejectsDays === 1 ? 'a day' : "{$rejectsDays} days" }} in case the reviewer was wrong</summary>
                    <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-6">
                        @foreach ($rejectedImages as $output)
                            <figure wire:key="rejected-{{ $output->id }}">
                                @include('livewire.requests._removed-or-thumb', ['output' => $output])
                                <figcaption class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $output->reason }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                </details>
            @endif
            @foreach ($generation->ads->groupBy('placement_id') as $placementId => $ads)
                @php($placement = $ads->first()->placement)
                <div wire:key="ads-{{ $generation->id }}-{{ $placementId }}" class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <h4 class="text-sm font-medium">Finished ads · {{ $placement?->label() ?? 'placement' }} <span class="text-xs font-normal text-zinc-500">{{ $placement?->sizeLabel() }}</span></h4>
                    @php($rejectedAds = $request->status === 'done' ? $ads->where('status', 'rejected') : collect())
                    <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-6">
                        @foreach ($ads->reject(fn ($a) => $rejectedAds->contains($a)) as $ad)
                            <figure wire:key="ad-{{ $ad->id }}">
                                <a href="{{ $ad->url() }}" target="_blank" class="block overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800" title="Open at full size">
                                    <img src="{{ $ad->thumbUrl() }}" alt="Finished ad {{ $ad->position }}" class="w-full object-contain" loading="lazy" />
                                </a>
                                <figcaption @class(['mt-0.5 text-xs', 'text-zinc-500 dark:text-zinc-400' => $ad->passedChecks(), 'text-amber-700 dark:text-amber-400' => ! $ad->passedChecks()])>
                                    {{ $ad->passedChecks() ? 'Checks passed' : implode(' ', $ad->failedChecks()) }} · <a href="{{ $ad->url() }}?download=1" class="underline">Download</a>
                                </figcaption>
                                @include('livewire.requests._verdict', ['output' => $ad])
                            </figure>
                        @endforeach
                    </div>
                    @if ($rejectedAds->isNotEmpty())
                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs text-zinc-500 select-none dark:text-zinc-400">Rejected: {{ $rejectedAds->count() }} {{ Str::plural('ad', $rejectedAds->count()) }}, kept {{ $rejectsDays === 1 ? 'a day' : "{$rejectsDays} days" }}</summary>
                            <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-6">
                                @foreach ($rejectedAds as $ad)
                                    <figure wire:key="rejected-ad-{{ $ad->id }}">
                                        @include('livewire.requests._removed-or-thumb', ['output' => $ad])
                                        <figcaption class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $ad->reason }}</figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</section>
