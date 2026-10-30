<div class="max-w-4xl space-y-6" @if ($request->isBusy()) wire:poll.4s @endif>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-zinc-600 dark:text-zinc-400">{{ $request->user->name }} · {{ $request->created_at->diffForHumans() }} · {{ $request->public ? 'Public' : 'Private' }}</p>
            <p class="mt-2 whitespace-pre-line">{{ $request->brief }}</p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ \App\Models\CreativeRequest::OUTPUTS[$request->output] }}{{ $request->output !== 'image' ? ', '.strtolower(explode(':', \App\Models\CreativeRequest::AD_STYLES[$request->ad_style])[0]) : '' }} · {{ \App\Models\CreativeRequest::COPY[$request->copy] }} · {{ implode(', ', $request->languageNames()) }} · {{ count($request->placement_ids ?? []) }} {{ Str::plural('placement', count($request->placement_ids ?? [])) }}
            </p>
            @if ($request->references->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($request->references as $ref)
                        <figure wire:key="ref-{{ $ref->id }}" class="w-24">
                            <img src="{{ $ref->url() }}" alt="{{ $ref->original_name }}" class="h-24 w-24 rounded-md object-cover" />
                            <figcaption class="truncate text-xs text-zinc-500">{{ $ref->kindLabel() }}</figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-1">
            <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $request->statusLabel() }}</span>
            @if ($canUpdate)
                <x-button size="sm" variant="ghost" wire:click="setPublic({{ $request->public ? 'false' : 'true' }})">{{ $request->public ? 'Make private' : 'Make public' }}</x-button>
                @if (($request->isOpen() || $request->status === 'failed') && ! $request->hasRun())
                    <x-button size="sm" variant="ghost" wire:click="edit">Change the brief</x-button>
                @endif
                @if ($request->isOpen() || $request->status === 'failed' || $request->status === 'running')
                    <x-button size="sm" variant="ghost" wire:click="cancel">{{ $request->status === 'running' ? 'Stop the run' : 'Cancel request' }}</x-button>
                @endif
            @endif
        </div>
    </div>

    @if ($editing)
        <form wire:submit="saveChanges" class="space-y-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="font-semibold">Change the brief</h2>
            @include('livewire.requests._form')
            <div class="flex gap-2">
                <x-button type="submit" size="sm" wire:loading.attr="disabled">Plan it again</x-button>
                <x-button type="button" size="sm" variant="ghost" wire:click="cancelEdit">Keep as it is</x-button>
            </div>
        </form>
    @elseif ($request->status === 'planning')
        <p class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white p-5 text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
            <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            The planner is reading the brief{{ $request->rounds > 0 ? ' and your answers' : '' }}. This takes a minute or so.
        </p>
    @elseif ($request->status === 'failed' && ! $request->hasRun())
        <div class="rounded-lg border border-red-200 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950/40">
            <p class="text-red-800 dark:text-red-300">{{ $request->error ?: 'The planner stopped before it finished.' }}</p>
            @if ($canUpdate)
                <div class="mt-3"><x-button size="sm" variant="secondary" wire:click="retry">Try again</x-button></div>
            @endif
        </div>
    @elseif ($request->status === 'asking')
        <form wire:submit="answer" class="space-y-5 rounded-lg border border-amber-200 bg-white p-5 dark:border-amber-900 dark:bg-zinc-900">
            <h2 class="font-semibold">The planner has {{ count($request->questions) === 1 ? 'a question' : 'a few questions' }}</h2>
            @foreach ($request->questions as $q)
                <div wire:key="q-{{ $q['id'] }}">
                    <p class="font-medium">{{ $q['question'] }}</p>
                    @if (($q['options'] ?? []) !== [])
                        <div @class(['mt-2', 'grid gap-2 sm:grid-cols-3' => in_array($q['type'], ['product', 'variant']), 'space-y-1' => ! in_array($q['type'], ['product', 'variant'])])>
                            @foreach ($q['options'] as $option)
                                <label @class(['flex cursor-pointer items-center gap-2', 'rounded-md border border-zinc-200 p-2 has-checked:border-zinc-900 dark:border-zinc-800 dark:has-checked:border-white' => in_array($q['type'], ['product', 'variant'])])>
                                    <input type="radio" wire:model="answers.{{ $q['id'] }}" value="{{ $option['value'] }}" class="border-zinc-300 dark:border-zinc-700">
                                    @if (! empty($option['image']))
                                        <img src="{{ $option['image'] }}" alt="" class="h-12 w-12 rounded object-cover" />
                                    @endif
                                    <span class="text-sm">{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                            @if ($q['type'] === 'variant')
                                <label class="flex cursor-pointer items-center gap-2 rounded-md border border-zinc-200 p-2 has-checked:border-zinc-900 dark:border-zinc-800 dark:has-checked:border-white"><input type="radio" wire:model="answers.{{ $q['id'] }}" value="any" class="border-zinc-300 dark:border-zinc-700"> <span class="text-sm">Any: the picture doesn't need a specific one</span></label>
                            @endif
                            @if ($q['allow_other'] ?? false)
                                <label class="flex items-center gap-2"><input type="radio" wire:model="answers.{{ $q['id'] }}" value="__other" class="border-zinc-300 dark:border-zinc-700"> <x-form.input wire:model="other.{{ $q['id'] }}" placeholder="Something else…" class="max-w-sm py-1" /></label>
                            @endif
                        </div>
                    @else
                        <x-form.input wire:model="answers.{{ $q['id'] }}" class="mt-2 max-w-md" />
                    @endif
                    @error("answers.{$q['id']}") <p class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endforeach
            @if ($canUpdate)
                <x-button type="submit" size="sm" wire:loading.attr="disabled">Send the answers</x-button>
            @else
                <p class="text-xs text-zinc-500">Waiting on {{ $request->user->name }}.</p>
            @endif
        </form>
    @elseif ($request->plan)
        @if ($request->hasRun())
            @include('livewire.requests._outputs')
            @include('livewire.requests._copy')
        @endif
        <details class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900" @if (! $request->hasRun()) open @endif>
            <summary class="cursor-pointer px-5 py-3 font-semibold select-none">The plan{{ $request->hasRun() ? ' and what it was estimated at' : '' }}</summary>
            <section class="space-y-5 border-t border-zinc-200 p-5 dark:border-zinc-800">
            <div>
                <p class="mt-1">{{ $request->plan('summary') }}</p>
                <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    @if ($request->plan('offer.headline')) <dt class="text-zinc-500">Offer</dt><dd>{{ $request->plan('offer.headline') }}{{ $request->plan('offer.details') ? ' · '.$request->plan('offer.details') : '' }}</dd> @endif
                    @if ($request->plan('audience')) <dt class="text-zinc-500">Audience</dt><dd>{{ $request->plan('audience') }}</dd> @endif
                    @if ($request->plan('cta')) <dt class="text-zinc-500">Call to action</dt><dd>{{ $request->plan('cta') }}</dd> @endif
                    <dt class="text-zinc-500">Product</dt><dd>{{ $request->variant ? $request->variant->product->title.': '.$request->variant->label() : 'None' }}{{ $request->plan('product_note') ? ' · '.$request->plan('product_note') : '' }}</dd>
                    <dt class="text-zinc-500">Image prompt</dt><dd class="whitespace-pre-line">{{ $request->plan('image_prompt') }}</dd>
                    @foreach ($request->plan('ratio_notes', []) as $ratio => $note)
                        <dt class="text-zinc-500">{{ $ratio }}</dt><dd>{{ $note }}</dd>
                    @endforeach
                    <dt class="text-zinc-500">Model</dt><dd>{{ $rows[0]['model']?->label ?? $request->plan('image_model') }}{{ $request->plan('model_reason') ? ' · '.$request->plan('model_reason') : '' }}</dd>
                    @if ($promptsUsed !== [])
                        <dt class="text-zinc-500">Prompts used</dt><dd>{{ implode('; ', $promptsUsed) }} · <a href="{{ route('prompts', $brand) }}" wire:navigate class="underline">History</a></dd>
                    @endif
                </dl>
            </div>

            <table class="w-full text-left text-sm">
                <thead class="text-xs text-zinc-500 uppercase dark:text-zinc-400">
                    <tr><th class="py-1 font-medium">Shape</th><th class="py-1 font-medium">Placements</th><th class="py-1 text-right font-medium">Images</th><th class="py-1 text-right font-medium">Estimate</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($rows as $row)
                        <tr wire:key="row-{{ $row['ratio'] }}" class="align-top">
                            <td class="py-1.5 whitespace-nowrap">{{ $row['ratio'] }}</td>
                            <td class="py-1.5 text-zinc-600 dark:text-zinc-400">{{ implode(', ', $row['placements']) }}</td>
                            <td class="py-1.5 text-right">{{ $row['count'] }}</td>
                            <td class="py-1.5 text-right whitespace-nowrap">${{ \App\Budgets\Budgets::money($row['cost']) }}</td>
                        </tr>
                    @endforeach
                    <tr><td colspan="3" class="py-1.5 text-zinc-600 dark:text-zinc-400">Writing, copy and review, allowed for</td><td class="py-1.5 text-right whitespace-nowrap">${{ \App\Budgets\Budgets::money($request->estimated_cost - $request->image_cost) }}</td></tr>
                    <tr class="font-medium"><td colspan="3" class="py-1.5">Estimated cost of the run</td><td class="py-1.5 text-right whitespace-nowrap">${{ \App\Budgets\Budgets::money($request->estimated_cost) }}</td></tr>
                </tbody>
            </table>

            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ $request->user->name }} has ${{ \App\Budgets\Budgets::money($person['remaining']) }} of ${{ \App\Budgets\Budgets::money($person['limit']) }} left this month; the company has ${{ \App\Budgets\Budgets::money($company['remaining']) }} of ${{ \App\Budgets\Budgets::money($company['limit']) }}.
            </p>

            @if ($request->status === 'planned')
                @if ($budgetProblem)
                    <p class="rounded-md bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">{{ $budgetProblem }}</p>
                @elseif ($canUpdate)
                    <div class="flex items-center gap-3">
                        <x-button wire:click="confirm" wire:loading.attr="disabled">Confirm and run</x-button>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">This spends money with the providers.</span>
                    </div>
                @endif
            @endif
            </section>
        </details>
    @elseif ($request->status === 'cancelled')
        <p class="text-zinc-500 dark:text-zinc-400">This request was cancelled.</p>
    @endif
</div>
