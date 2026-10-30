<?php

namespace App\Budgets;

use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\Spend;
use App\Models\User;
use App\Plugins\PluginRegistry;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What was spent, added up by person, brand, provider, kind of work and
 * month, so estimates can be checked against real spend and the budgets
 * set with some idea of what's used.
 */
class SpendReport
{
    public const PERIODS = ['month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year', 'all' => 'All time'];

    /** What each kind of spend row is, in plain words. */
    public const KINDS = ['plan' => 'Planning', 'image' => 'Images', 'copy' => 'Copy', 'review' => 'Review', 'change' => 'Changes', 'prompt' => 'Prompt drafting', 'try' => 'Prompt tries', 'cutout' => 'Cut-outs', 'video' => 'Video'];

    public function __construct(private Settings $settings, private PluginRegistry $plugins) {}

    /**
     * The period's start and end in UTC (null for no bound), from the platform's time zone.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function range(string $period): array
    {
        $now = CarbonImmutable::now((string) $this->settings->get('platform.timezone'));

        return match ($period) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth()->utc(), $now->startOfMonth()->utc()],
            'year' => [$now->startOfYear()->utc(), null],
            'all' => [null, null],
            default => [$now->startOfMonth()->utc(), null],
        };
    }

    public function total(string $period, ?int $brandId = null): float
    {
        return round((float) $this->rows($period, $brandId)->sum('amount'), 4);
    }

    /**
     * Totals by one column (user_id, brand_id, provider or kind), largest first, with each one's share.
     *
     * @return Collection<int, array{key: string, label: string, amount: float, share: float}>
     */
    public function by(string $period, string $column, ?int $brandId = null): Collection
    {
        $rows = $this->rows($period, $brandId)->selectRaw("{$column} as `key`, sum(amount) as amount")->groupBy($column)->orderByDesc('amount')->orderBy($column)->get();
        $total = (float) $rows->sum('amount');
        $labels = $this->labels($column, $rows->pluck('key')->filter()->all());

        return $rows->map(fn ($row) => [
            'key' => (string) $row->key,
            'label' => $labels[(string) $row->key] ?? ($row->key === null ? 'Nobody in particular' : (string) $row->key),
            'amount' => round((float) $row->amount, 4),
            'share' => $total > 0 ? (int) round((float) $row->amount / $total * 100) : 0,
        ])->values();
    }

    /**
     * The last months, oldest first: what was spent and how many requests were made.
     *
     * @return list<array{month: string, amount: float, requests: int}>
     */
    public function byMonth(?int $brandId = null, int $months = 12): array
    {
        $tz = (string) $this->settings->get('platform.timezone');
        $start = CarbonImmutable::now($tz)->startOfMonth()->subMonthsNoOverflow($months - 1);

        $spend = Spend::query()->where('created_at', '>=', $start->utc())->when($brandId, fn ($q) => $q->where('brand_id', $brandId))->get(['amount', 'created_at'])
            ->groupBy(fn (Spend $s) => $s->created_at->timezone($tz)->format('Y-m'))->map(fn ($g) => (float) $g->sum('amount'));
        $requests = CreativeRequest::query()->where('created_at', '>=', $start->utc())->when($brandId, fn ($q) => $q->where('brand_id', $brandId))->get(['created_at'])
            ->groupBy(fn (CreativeRequest $r) => $r->created_at->timezone($tz)->format('Y-m'))->map->count();

        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonthsNoOverflow($i);
            $key = $month->format('Y-m');
            $rows[] = ['month' => $month->format('M Y'), 'amount' => round($spend[$key] ?? 0, 4), 'requests' => (int) ($requests[$key] ?? 0)];
        }

        return $rows;
    }

    /**
     * The period's requests with what they were estimated at and what they cost, the dearest first.
     *
     * @return Collection<int, CreativeRequest>
     */
    public function requests(string $period, ?int $brandId = null, int $limit = 25): Collection
    {
        [$from, $to] = $this->range($period);

        return CreativeRequest::query()->with(['user', 'brand'])
            ->whereNotNull('confirmed_at')
            ->when($from, fn ($q) => $q->where('confirmed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('confirmed_at', '<', $to))
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->orderByDesc('actual_cost')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * @return Builder<Spend>
     */
    private function rows(string $period, ?int $brandId): Builder
    {
        [$from, $to] = $this->range($period);

        return Spend::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<', $to))
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId));
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function labels(string $column, array $keys): array
    {
        return match ($column) {
            'user_id' => User::whereIn('id', $keys)->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
            'brand_id' => Brand::whereIn('id', $keys)->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all(),
            'provider' => collect($keys)->mapWithKeys(fn ($k) => [$k => $this->plugins->plugin($k)?->name() ?? ucfirst($k)])->all(),
            'kind' => collect($keys)->mapWithKeys(fn ($k) => [$k => self::KINDS[$k] ?? ucfirst($k)])->all(),
            default => [],
        };
    }
}
