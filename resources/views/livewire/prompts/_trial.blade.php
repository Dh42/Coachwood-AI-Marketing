{{-- What a try of the prompt gave back: running, failed, or the result by prompt type. --}}
@php($status = $trialResult['status'] ?? 'failed')
@php($r = $trialResult['result'] ?? [])
<div class="mt-4 rounded-md border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950/40" @if ($status === 'running') wire:poll.3s="pollTrial" @endif>
    @if ($status === 'running')
        <p class="text-zinc-600 dark:text-zinc-400">
            <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-zinc-400 align-middle"></span>
            Trying it… {{ $promptType === \App\Prompts\PromptType::Image ? 'Making the picture can take a minute.' : 'This takes a few seconds; the planner a little longer when it searches the store.' }}
        </p>
    @elseif ($status === 'failed')
        <p class="text-red-600 dark:text-red-400">{{ $trialResult['error'] ?? 'The try failed.' }}</p>
    @else
        <h4 class="font-medium">What came back</h4>

        @switch($promptType)
            @case(\App\Prompts\PromptType::Planner)
                @if (($r['kind'] ?? '') === 'questions')
                    <p class="mt-1 text-zinc-600 dark:text-zinc-400">The planner would ask first:</p>
                    <ol class="mt-1 list-decimal space-y-1 pl-5">
                        @foreach ($r['questions'] as $q)
                            <li>{{ $q['question'] }}@if ($q['options'] !== []) <span class="text-zinc-500 dark:text-zinc-400">({{ implode(' / ', $q['options']) }}@if ($q['allow_other']) / something else @endif)</span> @endif</li>
                        @endforeach
                    </ol>
                @else
                    @php($plan = $r['plan'] ?? [])
                    <dl class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                        @foreach (['title' => 'Title', 'summary' => 'Summary', 'offer' => 'Offer', 'audience' => 'Audience', 'cta' => 'Call to action', 'product' => 'Product', 'product_note' => 'About the product', 'image_model' => 'Image model', 'model_reason' => 'Why that model'] as $key => $label)
                            @if (filled($plan[$key] ?? null))
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt><dd>{{ $plan[$key] }}</dd>
                            @endif
                        @endforeach
                        @if (filled($plan['image_prompt'] ?? null))
                            <dt class="text-zinc-500 dark:text-zinc-400">Image prompt</dt><dd class="whitespace-pre-line">{{ $plan['image_prompt'] }}</dd>
                        @endif
                        @foreach ($plan['ratio_notes'] ?? [] as $ratio => $note)
                            <dt class="text-zinc-500 dark:text-zinc-400">For {{ $ratio }}</dt><dd>{{ $note }}</dd>
                        @endforeach
                    </dl>
                    @if (blank($plan['product'] ?? null))
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">No product was chosen from the store.</p>
                    @endif
                @endif
                @break

            @case(\App\Prompts\PromptType::Image)
                <div class="mt-2 flex flex-wrap items-start gap-4">
                    <img src="{{ $trials->fileUrl($brand, $r['thumb'] ?? $r['path']) }}" alt="The image the try made" class="w-72 max-w-full rounded-md border border-zinc-200 dark:border-zinc-700">
                    <details class="min-w-0 flex-1 text-sm">
                        <summary class="cursor-pointer text-zinc-600 dark:text-zinc-400">The prompt sent, {{ $r['width'] }}×{{ $r['height'] }}</summary>
                        <pre class="mt-2 rounded-md border border-zinc-200 bg-white p-3 font-mono text-xs whitespace-pre-wrap dark:border-zinc-800 dark:bg-zinc-900">{{ $r['prompt'] }}</pre>
                    </details>
                </div>
                @break

            @case(\App\Prompts\PromptType::Copy)
                <p class="mt-1 text-zinc-600 dark:text-zinc-400">Written for {{ $r['placement'] }}, in English:</p>
                <div class="mt-2 grid gap-3 md:grid-cols-3">
                    @foreach ($r['versions'] as $i => $version)
                        <div class="rounded-md border border-zinc-200 bg-white p-3 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="mb-1 text-xs font-medium text-zinc-500 uppercase dark:text-zinc-400">Version {{ $i + 1 }}</div>
                            @foreach ($version['checks'] as $field => $check)
                                @php($f = \App\Platforms\CopyField::tryFrom($field))
                                <div class="mt-2">
                                    <div class="flex items-baseline justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        <span>{{ $f?->label() ?? ucfirst($field) }}</span>
                                        <span @class(['text-red-600 dark:text-red-400' => ! $check['ok']])>{{ $check['count'] }}/{{ $check['limit'] }}</span>
                                    </div>
                                    <p class="whitespace-pre-line">{{ is_array($version[$field]) ? implode(' ', $version[$field]) : $version[$field] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @break

            @case(\App\Prompts\PromptType::Change)
                <dl class="mt-2 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    <dt class="text-zinc-500 dark:text-zinc-400">How</dt>
                    <dd>{{ $r['mode'] === 'edit' ? 'An edit of the original image, sent along with the instruction' : 'The prompt written again' }}@if ($r['image_model']) ({{ $r['image_model'] }}{{ $r['can_edit'] ? ' can edit an image' : ' can\'t edit an image' }})@endif</dd>
                    @if (filled($r['instruction']))
                        <dt class="text-zinc-500 dark:text-zinc-400">Instruction</dt><dd>{{ $r['instruction'] }}</dd>
                    @endif
                    @if ($r['keep'] !== [])
                        <dt class="text-zinc-500 dark:text-zinc-400">Keep as it is</dt><dd>{{ implode('; ', $r['keep']) }}</dd>
                    @endif
                </dl>
                <details class="mt-2 text-sm">
                    <summary class="cursor-pointer text-zinc-600 dark:text-zinc-400">The prompt the image model would get</summary>
                    <pre class="mt-2 rounded-md border border-zinc-200 bg-white p-3 font-mono text-xs whitespace-pre-wrap dark:border-zinc-800 dark:bg-zinc-900">{{ $r['prompt'] }}</pre>
                </details>
                @break

            @default
                <div class="mt-2 flex flex-wrap items-start gap-4">
                    <img src="{{ $r['thumb'] }}" alt="" class="w-40 rounded-md border border-zinc-200 dark:border-zinc-700">
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="text-zinc-600 dark:text-zinc-400">{{ $r['what'] }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ $r['overall'] }} <span class="text-base font-normal text-zinc-500 dark:text-zinc-400">overall with this prompt@if ($r['saved']), {{ $r['saved']['overall'] }} by the saved review@endif</span></p>
                        @if ($r['hard_fails'] !== [])
                            <p class="mt-1 text-amber-700 dark:text-amber-400">Hard fails: {{ implode('; ', $r['hard_fails']) }}</p>
                        @endif
                        @if (filled($r['reason']))
                            <p class="mt-1">{{ $r['reason'] }}</p>
                        @endif
                        <table class="mt-2 w-full text-left text-xs">
                            <thead class="text-zinc-500 dark:text-zinc-400"><tr><th class="py-1 font-medium">Criterion</th><th class="py-1 text-right font-medium">Weight</th><th class="py-1 text-right font-medium">Score</th><th class="py-1 pl-3 font-medium">Note</th></tr></thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach ($r['scores'] as $score)
                                    <tr><td class="py-1">{{ $score['label'] }}</td><td class="py-1 text-right tabular-nums">{{ $score['weight'] }}</td><td class="py-1 text-right tabular-nums">{{ $score['score'] }}</td><td class="py-1 pl-3 text-zinc-500 dark:text-zinc-400">{{ $score['note'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if ($r['saved'] && filled($r['saved']['reason']))
                            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">The saved review said: {{ $r['saved']['reason'] }}</p>
                        @endif
                    </div>
                </div>
        @endswitch
    @endif

    @if ($status !== 'running')
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
            <span>{{ ($trialResult['cost'] ?? 0) > 0 ? 'Cost $'.\App\Budgets\Budgets::money($trialResult['cost']) : 'No cost recorded' }}{{ filled($trialResult['model'] ?? null) ? ' · '.$trialResult['model'] : '' }} · Nothing was saved.</span>
            <x-button size="sm" variant="ghost" wire:click="dismissTrial">Dismiss</x-button>
        </div>
    @endif
</div>
