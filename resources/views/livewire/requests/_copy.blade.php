{{-- The copy written for the request: per placement and language, several versions each, counted against the limits. --}}
@php($copies = $request->copies()->with('placement.platform')->get())
@if ($copies->isNotEmpty() || ($request->copy !== 'none' && $request->status === 'running'))
    <section class="space-y-4">
        <h2 class="font-semibold">Copy</h2>
        @foreach ($copies->where('kind', 'ad')->sortBy('language') as $words)
            <div wire:key="adwords-{{ $words->id }}" class="rounded-lg border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-sm font-medium">Words on the finished ads · {{ \App\Models\CreativeRequest::LANGUAGES[$words->language] ?? $words->language }}</h3>
                @if ($words->status === 'failed')
                    <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $words->error }}</p>
                @elseif ($text = $words->adText())
                    <p class="mt-1 text-sm">{{ $text['headline'] }} <span class="text-zinc-500">· {{ $text['subline'] }} · {{ $text['cta'] }}</span></p>
                @endif
            </div>
        @endforeach
        @foreach ($copies->where('kind', 'placement')->groupBy('placement_id') as $placementId => $group)
            @php($placement = $group->first()->placement)
            <div wire:key="copy-{{ $placementId }}" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="border-b border-zinc-200 px-4 py-2 text-sm font-medium dark:border-zinc-800">{{ $placement?->label() ?? 'Placement' }}</h3>
                @foreach ($group->sortBy('language') as $copy)
                    <div wire:key="copy-{{ $copy->id }}" class="border-b border-zinc-100 px-4 py-3 last:border-b-0 dark:border-zinc-800">
                        @if (count($request->languages ?? []) > 1)
                            <div class="mb-2 text-xs font-medium text-zinc-500 uppercase dark:text-zinc-400">{{ \App\Models\CreativeRequest::LANGUAGES[$copy->language] ?? $copy->language }}</div>
                        @endif
                        @if ($copy->status === 'failed')
                            <p class="text-xs text-red-700 dark:text-red-400">{{ $copy->error }}</p>
                        @else
                            <div class="grid gap-3 md:grid-cols-{{ min(3, max(1, count($copy->versions))) }}">
                                @foreach ($copy->versions as $i => $version)
                                    <div wire:key="copy-{{ $copy->id }}-{{ $i }}" class="space-y-2 rounded-md border border-zinc-100 p-3 text-sm dark:border-zinc-800">
                                        @foreach ($version['checks'] as $field => $check)
                                            @php($value = is_array($version[$field] ?? null) ? implode(' ', $version[$field]) : ($version[$field] ?? ''))
                                            <div x-data>
                                                <div class="flex items-baseline justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                                    <span>{{ \App\Platforms\CopyField::from($field)->label() }}</span>
                                                    <span @class(['text-red-600 dark:text-red-400' => ! $check['ok']])>{{ $check['count'] }}/{{ $check['limit'] }} <button type="button" class="ml-1 underline" @click="navigator.clipboard.writeText(@js($value)); $dispatch('saved', { message: 'Copied.' })">Copy</button></span>
                                                </div>
                                                <p class="whitespace-pre-line">{{ $value }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
        @if ($request->status === 'running' && $copies->where('kind', 'placement')->isEmpty() && $request->copy !== 'none')
            <p class="text-xs text-zinc-500 dark:text-zinc-400">The copywriter is at work…</p>
        @endif
    </section>
@endif
