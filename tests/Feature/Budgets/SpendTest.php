<?php

use App\Auth\Role;
use App\Budgets\Budgets;
use App\Budgets\SpendReport;
use App\Livewire\Budgets\SpendPage;
use App\Livewire\Users\ManageUsers;
use App\Models\CreativeRequest;
use App\Models\Spend;
use App\Models\User;
use App\Models\UserBudget;
use App\Notifications\BudgetAlert;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin, ['name' => 'Ada Admin']);
    $this->maker = User::factory()->withRole(Role::Creator)->create(['name' => 'Bea Maker']);
    $this->cp = makeBrand(['name' => 'Canadian Protein']);
    $this->bs = makeBrand(['name' => 'BioSteel']);
    $this->budgets = app(Budgets::class);
    $this->report = app(SpendReport::class);
});

function spent(string $provider, string $kind, float $amount, $brand, $user, $daysAgo = 0): void
{
    Spend::create(['brand_id' => $brand->id, 'user_id' => $user->id, 'provider' => $provider, 'kind' => $kind, 'amount' => $amount, 'created_at' => now()->subDays($daysAgo)]);
}

it('adds spend up by person, brand, provider, kind and month, for a period and a brand', function () {
    $this->travelTo(now()->setDay(15));
    spent('gemini', 'image', 3, $this->cp, $this->admin);
    spent('gemini', 'image', 1, $this->bs, $this->maker);
    spent('anthropic', 'plan', 0.5, $this->cp, $this->maker);
    spent('anthropic', 'review', 0.5, $this->cp, $this->admin);
    spent('openai', 'image', 10, $this->cp, $this->maker, 40);  // last month
    spent('openai', 'image', 100, $this->bs, $this->admin, 400);  // last year

    expect($this->report->total('month'))->toBe(5.0)->and($this->report->total('month', $this->cp->id))->toBe(4.0)
        ->and($this->report->total('last_month'))->toBe(10.0)->and($this->report->total('year'))->toBe(15.0)->and($this->report->total('all'))->toBe(115.0)
        ->and($this->report->by('month', 'user_id')->map(fn ($r) => [$r['label'], $r['amount'], $r['share']])->all())->toBe([['Ada Admin', 3.5, 70], ['Bea Maker', 1.5, 30]])
        ->and($this->report->by('month', 'brand_id')->pluck('amount', 'label')->all())->toBe(['Canadian Protein' => 4.0, 'BioSteel' => 1.0])
        ->and($this->report->by('month', 'provider')->pluck('amount', 'label')->all())->toBe(['Google Gemini' => 4.0, 'Anthropic' => 1.0])
        ->and($this->report->by('month', 'kind')->pluck('amount', 'label')->all())->toBe(['Images' => 4.0, 'Planning' => 0.5, 'Review' => 0.5])
        ->and($this->report->by('month', 'kind', $this->bs->id)->pluck('amount', 'label')->all())->toBe(['Images' => 1.0]);

    $months = $this->report->byMonth();
    expect($months)->toHaveCount(12)->and(end($months))->toMatchArray(['amount' => 5.0, 'requests' => 0])->and($months[10]['amount'])->toBe(10.0);

    Livewire::test(SpendPage::class)->assertOk()
        ->assertSee('$5.00')->assertSee('Ada Admin')->assertSee('70%')->assertSee('Google Gemini')->assertSee('Planning')->assertSee('$200.00 (default)')
        ->set('period', 'all')->assertSee('$115.00')
        ->set('brand', (string) $this->bs->id)->assertSee('$101.00')->assertDontSee('Canadian Protein</td>', false)
        ->set('period', 'nonsense')->assertSet('period', 'month');

    $this->get(route('spend'))->assertOk()->assertSee('The company this month');
    signInAs(Role::Creator);
    $this->get(route('spend'))->assertForbidden();
});

it('shows each request\'s estimate against what it cost, and the company\'s month with the warning line', function () {
    $request = CreativeRequest::create(['brand_id' => $this->cp->id, 'user_id' => $this->maker->id, 'status' => CreativeRequest::DONE, 'title' => 'Whey weekend', 'brief' => 'B', 'languages' => ['en'], 'output' => 'image', 'copy' => 'none', 'placement_ids' => [], 'estimated_cost' => 1.5, 'actual_cost' => 2.25, 'confirmed_at' => now()]);
    CreativeRequest::create(['brand_id' => $this->cp->id, 'user_id' => $this->maker->id, 'status' => CreativeRequest::PLANNED, 'title' => 'Not run', 'brief' => 'B', 'languages' => ['en'], 'output' => 'image', 'copy' => 'none', 'placement_ids' => [], 'estimated_cost' => 9]);

    expect($this->report->requests('month')->pluck('id')->all())->toBe([$request->id]);

    app(Settings::class)->set('budgets.company_monthly', 100, SettingScope::Platform, 0, $this->admin);
    spent('gemini', 'image', 85, $this->cp, $this->maker);

    Livewire::test(SpendPage::class)->assertOk()->assertSee('Whey weekend')->assertSee('$1.50')->assertSee('$2.25')->assertSee('+$0.75')->assertDontSee('Not run')
        ->assertSee('$85.00')->assertSee('of $100.00')->assertSee('Past the 80% warning line');
});

it('tells the platform admins once a month when the company passes the warning line', function () {
    Notification::fake();
    app(Settings::class)->set('budgets.company_monthly', 100, SettingScope::Platform, 0, $this->admin);
    $off = User::factory()->withRole(Role::Admin)->create(['disabled_at' => now()]);

    $this->budgets->record('gemini', 'image', 50, $this->cp, $this->maker);
    Notification::assertNothingSent();

    $this->budgets->record('gemini', 'image', 35, $this->cp, $this->maker);
    Notification::assertSentTo($this->admin, BudgetAlert::class, fn (BudgetAlert $n) => $n->percent === 85 && $n->title() === 'The company budget for '.now()->format('F').' is 85% used' && str_contains($n->body(), '$85.00 of the $100.00'));
    Notification::assertNotSentTo([$this->maker, $off], BudgetAlert::class);
    expect(Activity::inLog('budgets')->where('event', 'budget_alert')->count())->toBe(1);

    // Not again this month, however much more goes.
    $this->budgets->record('gemini', 'image', 10, $this->cp, $this->maker);
    Notification::assertSentTimes(BudgetAlert::class, 1);

    // Next month starts afresh.
    $this->travel(32)->days();
    $this->budgets->record('gemini', 'image', 95, $this->cp, $this->maker);
    Notification::assertSentTimes(BudgetAlert::class, 2);
});

it('lets an admin set each person\'s budget on Users, and put them back on the default', function () {
    spent('gemini', 'image', 12.5, $this->cp, $this->maker);

    $page = Livewire::test(ManageUsers::class)->assertSee('$12.50 of $200.00')->assertSee('the default')
        ->call('editBudget', $this->maker->id)->assertSet('budgetFor', $this->maker->id)->assertSet('budgetAmount', '200')
        ->set('budgetAmount', '-5')->call('saveBudget')->assertHasErrors('budgetAmount')
        ->set('budgetAmount', '350')->call('saveBudget')->assertHasNoErrors()->assertDispatched('saved')->assertSet('budgetFor', null)
        ->assertSee('$12.50 of $350.00')->assertSee('their own');
    expect(UserBudget::where('user_id', $this->maker->id)->sole()->monthly_usd)->toBe(350.0)
        ->and($this->budgets->person($this->maker)['remaining'])->toBe(337.5);

    $page->call('defaultBudget', $this->maker->id)->assertDispatched('saved')->assertSee('$12.50 of $200.00');
    expect(UserBudget::where('user_id', $this->maker->id)->exists())->toBeFalse();

    // Used up shows, and the page can be read without the budget permission only by admins anyway.
    $this->budgets->setPersonBudget($this->maker, 10, $this->admin);
    Livewire::test(ManageUsers::class)->assertSee('used up');
});
