<?php

namespace App\Budgets;

use App\Auth\Role;
use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\RoleAssignment;
use App\Models\Spend;
use App\Models\User;
use App\Models\UserBudget;
use App\Notifications\BudgetAlert;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * The company's and each person's monthly budget, what's been spent this
 * month, and whether a run may start. Every provider call is spend; a run
 * that was allowed to start always finishes, so budgets block starts, not
 * runs in progress. Confirmed runs reserve their estimate until they're done.
 */
class Budgets
{
    public function __construct(private Settings $settings) {}

    /**
     * Records what a provider call cost, as its usage priced by the model list.
     */
    public function record(string $provider, string $kind, float $amount, ?Brand $brand = null, ?User $user = null, ?CreativeRequest $request = null, ?string $note = null): void
    {
        Spend::create([
            'brand_id' => $brand?->id ?? $request?->brand_id, 'user_id' => $user?->id ?? $request?->user_id, 'request_id' => $request?->id,
            'provider' => $provider, 'kind' => $kind, 'amount' => round($amount, 5), 'note' => $note, 'created_at' => now(),
        ]);

        $this->warnAdmins();
    }

    /**
     * Tells the platform admins once a month when the company's spend
     * passes the warning line.
     */
    public function warnAdmins(): void
    {
        $company = $this->company();
        if (! $company['alert']) {
            return;
        }

        $month = $this->monthStart();
        if (Activity::inLog('budgets')->where('event', 'budget_alert')->where('created_at', '>=', $month)->exists()) {
            return;
        }

        $percent = (int) round($company['spent'] / $company['limit'] * 100);
        $label = $month->timezone((string) $this->settings->get('platform.timezone'))->format('F');
        activity('budgets')->event('budget_alert')->withProperties(['spent' => $company['spent'], 'limit' => $company['limit']])
            ->log("The company budget for {$label} is {$percent}% used: \$".self::money($company['spent']).' of $'.self::money($company['limit']));

        $admins = User::whereIn('id', RoleAssignment::where('role', Role::Admin)->where('brand_id', RoleAssignment::PLATFORM)->pluck('user_id'))
            ->get()->reject(fn (User $u) => $u->isDisabled());
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new BudgetAlert($company['spent'], $company['limit'], $percent, $label));
        }
    }

    /**
     * The company this month: limit, spent (including reserved estimates), remaining, and whether the alert line is passed.
     *
     * @return array{limit: float, spent: float, remaining: float, alert: bool}
     */
    public function company(): array
    {
        $limit = (float) $this->settings->get('budgets.company_monthly');
        $spent = $this->monthSpend();

        return ['limit' => $limit, 'spent' => $spent, 'remaining' => max(0, $limit - $spent), 'alert' => $limit > 0 && $spent >= $limit * (int) $this->settings->get('budgets.alert_percent') / 100];
    }

    /**
     * A person this month.
     *
     * @return array{limit: float, spent: float, remaining: float, own: bool}
     */
    public function person(User $user): array
    {
        $own = UserBudget::where('user_id', $user->id)->first();
        $limit = $own ? $own->monthly_usd : (float) $this->settings->get('budgets.user_monthly');
        $spent = $this->monthSpend($user);

        return ['limit' => $limit, 'spent' => $spent, 'remaining' => max(0, $limit - $spent), 'own' => $own !== null];
    }

    /**
     * Why a run costing this much can't start now, or null when it can.
     */
    public function problem(User $user, float $estimate): ?string
    {
        $person = $this->person($user);
        if ($estimate > $person['remaining']) {
            return sprintf('This run is estimated at $%s and you have $%s of your $%s monthly budget left. An admin can raise it.', self::money($estimate), self::money($person['remaining']), self::money($person['limit']));
        }

        $company = $this->company();
        if ($estimate > $company['remaining']) {
            return sprintf('This run is estimated at $%s and the company has $%s of its $%s monthly budget left.', self::money($estimate), self::money($company['remaining']), self::money($company['limit']));
        }

        return null;
    }

    public function setPersonBudget(User $user, ?float $monthly, User $by): void
    {
        if ($monthly === null) {
            UserBudget::where('user_id', $user->id)->delete();
        } else {
            UserBudget::updateOrCreate(['user_id' => $user->id], ['monthly_usd' => round($monthly, 2), 'set_by' => $by->id]);
        }

        activity('budgets')->causedBy($by)->performedOn($user)->event('budget_set')->withProperties(['monthly' => $monthly])
            ->log($monthly === null ? "Set {$user->name}'s budget back to the default" : "Set {$user->name}'s monthly budget to \$".self::money($monthly));
    }

    /**
     * Actual spend this month plus the estimates of confirmed runs still going.
     */
    public function monthSpend(?User $user = null): float
    {
        $from = $this->monthStart();

        $actual = (float) Spend::where('created_at', '>=', $from)->when($user, fn ($q) => $q->where('user_id', $user->id))->sum('amount');
        $reserved = (float) CreativeRequest::whereIn('status', [CreativeRequest::READY, CreativeRequest::RUNNING])->where('confirmed_at', '>=', $from)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))->sum('estimated_cost');

        return round($actual + $reserved, 4);
    }

    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now((string) $this->settings->get('platform.timezone'))->startOfMonth()->utc();
    }

    public static function money(float $amount): string
    {
        return number_format($amount, $amount < 1 && $amount > 0 ? 3 : 2);
    }
}
