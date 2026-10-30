<div class="max-w-5xl space-y-6">
    <p class="max-w-3xl text-zinc-600 dark:text-zinc-400">
        What the AI is told at each step for {{ $brand->name }}. Every save is kept as a version with who made it and how, so a bad batch can be traced and any version brought back. Change a prompt by hand, or describe what's going wrong and let the AI draft the change.
    </p>

    <nav class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-800">
        @foreach ($types as $t)
            <button type="button" wire:click="pick('{{ $t['value'] }}')" @class(['-mb-px border-b-2 px-3 py-2 font-medium', 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' => $type === $t['value'], 'border-transparent text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $type !== $t['value']])>{{ $t['label'] }}</button>
        @endforeach
    </nav>

    <section class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">{{ $promptType->label() }}</h2>
                <p class="text-zinc-600 dark:text-zinc-400">{{ $promptType->description() }}</p>
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                v{{ $current->number }} · {{ $current->methodLabel().($current->user ? ' by '.$current->user->name : '') }} · {{ $current->created_at->timezone($tz)->format('M j, Y H:i') }}
                · <button type="button" wire:click="toggleHistory" class="underline">{{ $showHistory ? 'Hide history' : 'History' }}</button>
            </p>
        </div>

        @if ($showHistory)
            <div class="mt-4 divide-y divide-zinc-100 rounded-md border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                @foreach ($history as $revision)
                    <div wire:key="rev-{{ $revision->id }}" class="px-3 py-2">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div>
                                <span class="font-medium">v{{ $revision->number }}</span>
                                @if ($revision->id === $current->id) <span class="ml-1 rounded bg-zinc-100 px-1.5 py-0.5 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">current</span> @endif
                                <span class="text-zinc-500 dark:text-zinc-400">· {{ $revision->methodLabel().($revision->user ? ' by '.$revision->user->name : '') }} · {{ $revision->created_at->timezone($tz)->format('M j, Y H:i') }}</span>
                                @if ($revision->note) <span class="text-zinc-700 dark:text-zinc-300">· {{ $revision->note }}</span> @endif
                            </div>
                            <div class="flex gap-1">
                                @if ($revision->number > 1)
                                    <x-button size="sm" variant="ghost" wire:click="showDiff({{ $revision->id }})">{{ $compare === $revision->id ? 'Hide changes' : 'What changed' }}</x-button>
                                @endif
                                @if ($canEdit && $revision->id !== $current->id)
                                    @if ($restoring === $revision->id)
                                        <x-button size="sm" variant="danger" wire:click="restore({{ $revision->id }})">Yes, use v{{ $revision->number }}</x-button>
                                        <x-button size="sm" variant="ghost" wire:click="keep">Keep</x-button>
                                    @else
                                        <x-button size="sm" variant="ghost" wire:click="askRestore({{ $revision->id }})">Use this version</x-button>
                                    @endif
                                @endif
                            </div>
                        </div>
                        @if ($compare === $revision->id && $compareRows)
                            <div class="mt-2">@include('livewire.prompts._diff', ['rows' => $compareRows, 'leftTitle' => 'v'.($revision->number - 1), 'rightTitle' => 'v'.$revision->number])</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($canEdit)
            <div class="mt-4 space-y-4">
                <x-form.field label="The prompt" name="body" :help="$promptType->placeholders() !== [] ? 'The app fills in: '.collect($promptType->placeholders())->map(fn ($what, $key) => '{'.$key.'} ('.$what.')')->implode('; ').'.' : null">
                    <x-form.textarea wire:model="body" id="body" rows="16" class="font-mono text-sm" />
                </x-form.field>
                @if ($missing !== [])
                    <p class="rounded-md bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                        The text no longer has {{ collect($missing)->map(fn ($m) => '{'.$m.'}')->implode(', ') }}, so the AI won't be given {{ count($missing) === 1 ? 'that' : 'those' }}. Fine if that's meant.
                    </p>
                @endif

                @if ($promptType->hasCriteria())
                    <div>
                        <div class="flex items-baseline justify-between">
                            <h3 class="font-medium">Criteria</h3>
                            <x-button size="sm" variant="ghost" wire:click="addCriterion">Add a criterion</x-button>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Each is scored 0 to 100; the overall is the weighted average, worked out by the app. Weights are relative, so they needn't add up to 100.</p>
                        @error('criteria') <p data-save-error class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        <div class="mt-2 space-y-2">
                            @foreach ($criteria as $i => $c)
                                <div wire:key="criterion-{{ $i }}" class="grid grid-cols-12 gap-2">
                                    <div class="col-span-4"><x-form.input wire:model="criteria.{{ $i }}.label" placeholder="Matches the brief" class="py-1" />@error("criteria.{$i}.label") <p data-save-error class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror</div>
                                    <div class="col-span-2"><x-form.input type="number" min="1" max="100" wire:model="criteria.{{ $i }}.weight" class="py-1" />@error("criteria.{$i}.weight") <p data-save-error class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror</div>
                                    <div class="col-span-5"><x-form.input wire:model="criteria.{{ $i }}.what" placeholder="What to look for" class="py-1" />@error("criteria.{$i}.what") <p data-save-error class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror</div>
                                    <div class="col-span-1 text-right"><x-button size="sm" variant="ghost" wire:click="removeCriterion({{ $i }})">Remove</x-button></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <div>
                        <div class="flex items-baseline justify-between">
                            <h3 class="font-medium">Hard fails</h3>
                            <x-button size="sm" variant="ghost" wire:click="addHardFail">Add a hard fail</x-button>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Any one of these rejects the output whatever it scored.</p>
                        <div class="mt-2 space-y-2">
                            @foreach ($hardFails as $i => $f)
                                <div wire:key="fail-{{ $i }}" class="flex gap-2">
                                    <div class="grow"><x-form.input wire:model="hardFails.{{ $i }}.label" placeholder="A person has distorted hands, face or body" class="py-1" />@error("hard_fails.{$i}.label") <p data-save-error class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror</div>
                                    <x-button size="sm" variant="ghost" wire:click="removeHardFail({{ $i }})">Remove</x-button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-end gap-3">
                    <x-form.field label="Note on this change" name="note" class="grow" help="Optional: a few words on why, kept with the version.">
                        <x-form.input wire:model="note" id="note" maxlength="200" class="py-1" />
                    </x-form.field>
                    <x-button wire:click="save" wire:loading.attr="disabled">{{ $draftRequest ? 'Save the adjusted draft' : 'Save as a new version' }}</x-button>
                </div>
            </div>

            <div class="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="font-medium">Try it before saving</h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Your test budget today: ${{ \App\Budgets\Budgets::money($tryBudget['spent']) }} of ${{ \App\Budgets\Budgets::money($tryBudget['limit']) }} used.</p>
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $trialInfo['help'] }} Nothing is saved: not the text, not the result.@if ($trialInfo['estimate'] !== null) About ${{ \App\Budgets\Budgets::money($trialInfo['estimate']) }} a try.@endif</p>

                <div class="mt-3 space-y-3">
                    @if (in_array($promptType, [\App\Prompts\PromptType::Planner, \App\Prompts\PromptType::Copy], true))
                        <x-form.field label="A sample brief" name="sampleBrief">
                            <x-form.textarea wire:model="sampleBrief" id="sampleBrief" rows="2" />
                        </x-form.field>
                    @endif
                    @if (in_array($promptType, [\App\Prompts\PromptType::Image, \App\Prompts\PromptType::Change], true))
                        <x-form.field :label="$promptType === \App\Prompts\PromptType::Image ? 'The scene, as the planner would write it' : 'The prompt the original image was made from'" name="sampleScene">
                            <x-form.textarea wire:model="sampleScene" id="sampleScene" rows="2" />
                        </x-form.field>
                    @endif
                    @if ($promptType === \App\Prompts\PromptType::Change)
                        <x-form.field label="The change asked for" name="sampleChange">
                            <x-form.input wire:model="sampleChange" id="sampleChange" maxlength="500" class="py-1" />
                        </x-form.field>
                    @endif
                    @if ($promptType->isReview())
                        @if ($trialInfo['output'])
                            <div class="flex items-center gap-3">
                                <img src="{{ $trialInfo['output']->thumbUrl() }}" alt="" class="h-16 w-16 rounded border border-zinc-200 object-cover dark:border-zinc-700">
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    Scores the latest {{ \App\Prompts\PromptTrials::outputName($promptType) }}: "{{ $trialInfo['output']->request->title ?: Str::limit($trialInfo['output']->request->brief, 40) }}", {{ $trialInfo['output']->ratio }} #{{ $trialInfo['output']->position }}.
                                    @if ($trialInfo['output']->review?->overall !== null) The saved review gave it {{ $trialInfo['output']->review->overall }}. @endif
                                </p>
                            </div>
                        @else
                            <p class="text-sm text-amber-700 dark:text-amber-400">Nothing to try it on yet: the latest {{ \App\Prompts\PromptTrials::outputName($promptType) }} made for {{ $brand->name }} is what gets scored. Run a request first.</p>
                        @endif
                    @endif
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button variant="secondary" wire:click="tryIt" wire:loading.attr="disabled" wire:target="tryIt" :disabled="$promptType->isReview() && ! $trialInfo['output']">
                            <span wire:loading.remove wire:target="tryIt">Try it</span><span wire:loading wire:target="tryIt">Starting…</span>
                        </x-button>
                        @error('try') <p data-save-error class="text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($trialResult)
                    @include('livewire.prompts._trial')
                @endif
            </div>

            <div class="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-800">
                <h3 class="font-medium">Describe the problem</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Say what keeps going wrong, in plain words; the AI drafts the change and shows you what it would alter before anything is saved.</p>
                @if ($draft)
                    <div class="mt-3 space-y-3">
                        <p class="rounded-md bg-zinc-50 px-3 py-2 text-zinc-700 dark:bg-zinc-950/40 dark:text-zinc-300"><span class="font-medium">The AI's change:</span> {{ $draft['explanation'] ?: 'No explanation given.' }}</p>
                        @include('livewire.prompts._diff', ['rows' => $draftRows, 'leftTitle' => 'Now (v'.$current->number.')', 'rightTitle' => 'Proposed'])
                        <div class="flex flex-wrap gap-2">
                            <x-button wire:click="acceptDraft" wire:loading.attr="disabled">Accept and save</x-button>
                            <x-button variant="secondary" wire:click="adjustDraft">Adjust it first</x-button>
                            <x-button variant="ghost" wire:click="discardDraft">Discard</x-button>
                        </div>
                        @error('problem') <p data-save-error class="text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="mt-2 flex flex-wrap items-start gap-2">
                        <div class="grow">
                            <x-form.textarea wire:model="problem" id="problem" rows="2" placeholder="It keeps passing images where the shaker is the wrong colour." />
                            @error('problem') <p data-save-error class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <x-button variant="secondary" wire:click="askAi" wire:loading.attr="disabled" wire:target="askAi">
                            <span wire:loading.remove wire:target="askAi">Draft a change</span><span wire:loading wire:target="askAi">Drafting…</span>
                        </x-button>
                    </div>
                @endif
            </div>
        @else
            <pre class="mt-4 rounded-md border border-zinc-200 bg-zinc-50 p-3 font-mono text-sm whitespace-pre-wrap dark:border-zinc-800 dark:bg-zinc-950/40">{{ $current->body }}</pre>
            @if ($promptType->hasCriteria())
                <dl class="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    @foreach ($current->criteria() as $c)
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $c['label'] }} ({{ $c['weight'] }})</dt><dd>{{ $c['what'] }}</dd>
                    @endforeach
                </dl>
                @if ($current->hardFails() !== [])
                    <p class="mt-2 text-sm"><span class="text-zinc-500 dark:text-zinc-400">Hard fails:</span> {{ collect($current->hardFails())->pluck('label')->implode('; ') }}</p>
                @endif
            @endif
        @endif
    </section>

    @if ($canAdd)
        <section class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="font-semibold">Your additions</h2>
            <p class="text-zinc-600 dark:text-zinc-400">Added after the brand's {{ Str::lower($promptType->label()) }} prompt whenever you run, and nobody else's. Admins can read them. Leave it empty for none.</p>
            <div class="mt-3">
                <x-form.textarea wire:model="addition" id="addition" rows="4" placeholder="Prefer outdoor settings; never show a gym." />
                @error('addition') <p data-save-error class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="mt-3"><x-button variant="secondary" wire:click="saveAddition" wire:loading.attr="disabled">Save my additions</x-button></div>
        </section>
    @endif

    @if ($others->isNotEmpty())
        <section class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="font-semibold">Additions by others</h2>
            <div class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($others as $other)
                    <div wire:key="other-{{ $other->id }}" class="py-2">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $other->user->name }}</div>
                        <p class="whitespace-pre-line">{{ $other->body() }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
