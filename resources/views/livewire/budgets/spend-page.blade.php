@php($money = fn (float $a) => '$'.\App\Budgets\Budgets::money($a))
<div class="max-w-5xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-3xl text-zinc-600 dark:text-zinc-400">
            What the AI providers have cost, as their usage priced by the model list: by person, brand, provider and kind of work, month by month, and each request's estimate against what it cost. Budgets are set under Settings → Budgets and spend, and each person's own on Users.
        </p>
        <div class="flex gap-2">
            <x-form.select wire:model.live="period" aria-label="Period" class="py-1.5">
                @foreach (\App\Budgets\SpendReport::PERIODS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-form.select>
            <x-form.select wire:model.live="brand" aria-label="Brand" class="py-1.5">
                <option value="">Every brand</option>
                @foreach ($brands as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-form.select>
        </div>
    </div>

    <section class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="text-xs font-medium text-zinc-500 uppercase dark:text-zinc-400">{{ \App\Budgets\SpendReport::PERIODS[$period] }}{{ $brand !== '' ? ' · '.($brands[(int) $brand] ?? '') : '' }}</h2>
            <p class="mt-1 text-2xl font-semibold">{{ $money($total) }}</p>
            <p class="text-zinc-500 dark:text-zinc-400">spent with the providers</p>
        </div>
        <div @class(['rounded-lg border bg-white p-5 dark:bg-zinc-900', 'border-amber-300 dark:border-amber-700' => $company['alert'], 'border-zinc-200 dark:border-zinc-800' => ! $company['alert']])>
            <h2 class="text-xs font-medium text-zinc-500 uppercase dark:text-zinc-400">The company this month</h2>
            <p class="mt-1 text-2xl font-semibold">{{ $money($company['spent']) }} <span class="text-base font-normal text-zinc-500 dark:text-zinc-400">of {{ $money($company['limit']) }}</span></p>
            <p @class(['text-amber-700 dark:text-amber-400' => $company['alert'], 'text-zinc-500 dark:text-zinc-400' => ! $company['alert']])>
                @if ($company['limit'] <= 0)
                    No company budget is set, so nothing is held back.
                @elseif ($company['remaining'] <= 0)
                    Used up: no new run can start until next month or until the budget is raised.
                @elseif ($company['alert'])
                    Past the {{ $alertPercent }}% warning line; {{ $money($company['remaining']) }} left, counting the estimates of runs still going.
                @else
                    {{ $money($company['remaining']) }} left, counting the estimates of runs still going. Admins are warned at {{ $alertPercent }}%.
                @endif
            </p>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([['People', $people, 'person'], ['Brands', $byBrand, 'brand'], ['Providers', $providers, 'provider'], ['Kinds of work', $kinds, 'kind']] as [$heading, $rows, $what])
            <section>
                <h2 class="mb-2 font-semibold">{{ $heading }}</h2>
                <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
                    <table class="w-full text-left">
                        <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-900 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ Str::ucfirst($what) }}</th>
                                <th class="px-4 py-2 text-right font-medium">Spent</th>
                                <th class="px-4 py-2 text-right font-medium">Share</th>
                                @if ($what === 'person' && $period === 'month') <th class="px-4 py-2 text-right font-medium">Budget</th> @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                            @forelse ($rows as $row)
                                <tr wire:key="{{ $what }}-{{ $row['key'] }}">
                                    <td class="px-4 py-2">{{ $row['label'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $money($row['amount']) }}</td>
                                    <td class="px-4 py-2 text-right text-zinc-500 tabular-nums dark:text-zinc-400">{{ $row['share'] }}%</td>
                                    @if ($what === 'person' && $period === 'month')
                                        @php($person = ($u = \App\Models\User::find($row['key'])) ? $budgets->person($u) : null)
                                        <td class="px-4 py-2 text-right text-zinc-500 tabular-nums dark:text-zinc-400">
                                            @if ($person)
                                                {{ $money($person['limit']) }}{{ $person['own'] ? '' : ' (default)' }}
                                                @if ($person['remaining'] <= 0) <span class="text-amber-700 dark:text-amber-400">used up</span> @endif
                                            @else — @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-4 text-center text-zinc-500 dark:text-zinc-400">Nothing spent{{ $period === 'all' ? ' yet' : ' in this period' }}.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    </div>

    <section>
        <h2 class="mb-2 font-semibold">Month by month</h2>
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="w-full text-left">
                <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">Month</th>
                        <th class="px-4 py-2 text-right font-medium">Requests</th>
                        <th class="px-4 py-2 text-right font-medium">Spent</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @foreach ($months as $row)
                        <tr wire:key="month-{{ $row['month'] }}" @class(['text-zinc-400 dark:text-zinc-500' => $row['amount'] == 0 && $row['requests'] === 0])>
                            <td class="px-4 py-1.5">{{ $row['month'] }}</td>
                            <td class="px-4 py-1.5 text-right tabular-nums">{{ $row['requests'] }}</td>
                            <td class="px-4 py-1.5 text-right tabular-nums">{{ $money($row['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2 class="mb-2 font-semibold">Requests: estimated against actual</h2>
        <p class="mb-2 text-zinc-500 dark:text-zinc-400">The dearest requests of the period. An estimate well under the actual means the model prices or the allowance for writing and review want a look.</p>
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
            <table class="w-full text-left">
                <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 font-medium">Request</th>
                        <th class="px-4 py-2 font-medium">By</th>
                        <th class="px-4 py-2 text-right font-medium">Estimated</th>
                        <th class="px-4 py-2 text-right font-medium">Actual</th>
                        <th class="px-4 py-2 text-right font-medium">Difference</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @forelse ($requests as $request)
                        <tr wire:key="request-{{ $request->id }}">
                            <td class="px-4 py-2">
                                <a href="{{ route('requests.show', [$request->brand, $request]) }}" class="font-medium hover:underline">{{ $request->title ?: Str::limit($request->brief, 50) }}</a>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $request->brand->name }} · {{ Str::ucfirst($request->status) }}</div>
                            </td>
                            <td class="px-4 py-2">{{ $request->user?->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $request->estimated_cost !== null ? $money((float) $request->estimated_cost) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $request->actual_cost !== null ? $money((float) $request->actual_cost) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                @if ($request->estimated_cost !== null && $request->actual_cost !== null)
                                    @php($diff = (float) $request->actual_cost - (float) $request->estimated_cost)
                                    <span @class(['text-amber-700 dark:text-amber-400' => $diff > 0.5, 'text-zinc-500 dark:text-zinc-400' => $diff <= 0.5])>{{ $diff >= 0 ? '+' : '−' }}{{ $money(abs($diff)) }}</span>
                                @else — @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-4 text-center text-zinc-500 dark:text-zinc-400">No request was confirmed in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
