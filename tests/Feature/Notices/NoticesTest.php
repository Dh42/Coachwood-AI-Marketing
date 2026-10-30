<?php

use App\Auth\Role;
use App\Images\ImageModels;
use App\Livewire\Notices\Bell;
use App\Livewire\Notices\NoticesPage;
use App\Livewire\Requests\RequestPage;
use App\Livewire\Settings\SettingsForm;
use App\Models\CreativeRequest;
use App\Models\Placement;
use App\Models\User;
use App\Notifications\RequestNotice;
use App\Platforms\PlatformLibrary;
use App\Requests\Requests;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeLlm;

beforeEach(function () {
    Storage::fake();
    $this->admin = signInAs(Role::Admin, ['name' => 'Ada Admin']);
    $this->brand = makeBrand(['name' => 'Canadian Protein']);
    app(PlatformLibrary::class)->addStarters($this->admin);
    app(ImageModels::class)->addStarters($this->admin);
    $this->requests = app(Requests::class);
    $this->feed = Placement::where('key', 'feed-portrait')->whereHas('platform', fn ($q) => $q->where('key', 'instagram'))->sole();
});

/**
 * @return array<string, mixed>
 */
function noticeInput(): array
{
    return ['brief' => 'Marketing image for a sale on whey protein isolate, 20% off this weekend.', 'languages' => ['en'], 'output' => 'image', 'copy' => 'none', 'placement_ids' => [(string) test()->feed->id]];
}

function plannerAsks(): FakeLlm
{
    return fakeLlm([['ask_questions', ['questions' => [['id' => 'dates', 'question' => 'Which dates?', 'type' => 'text']]]]]);
}

function plannerPlans(FakeLlm $llm): void
{
    $llm->push([['submit_plan', ['title' => 'Whey weekend', 'summary' => 'S', 'image_prompt' => 'A kitchen counter.', 'image_model' => 'gemini/gemini-nano-banana-2.1']]]);
}

/**
 * A notice straight into the database, as the sender would store it.
 */
function noticeFor(User $user, array $data = [], bool $read = false): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => RequestNotice::class,
        'data' => ['event' => RequestNotice::DONE, 'title' => '"Whey weekend" is ready', 'body' => '3 of 6 outputs passed review.', 'url' => route('notices'), 'request_id' => 1, 'brand_id' => 1, 'brand' => 'Canadian Protein', ...$data],
        'read_at' => $read ? now() : null,
    ]);
}

it('tells the owner when the planner stops for them: a question, then the plan, as a notice that opening the request marks read', function () {
    $llm = plannerAsks();
    $other = User::factory()->withRole(Role::Creator)->create();

    $request = $this->requests->create($this->brand, noticeInput(), $this->admin);
    $this->requests->plan($request);

    $notice = $this->admin->notifications()->sole();
    expect($notice->data['event'])->toBe(RequestNotice::NEEDS_YOU)->and($notice->data['title'])->toBe('The planner has a question about "Marketing image for a sale on whey protein isolate, 20% off..."')
        ->and($notice->data['body'])->toBe('Answer it and the plan is written.')->and($notice->data['url'])->toBe(route('requests.show', [$this->brand, $request]))
        ->and($notice->data['request_id'])->toBe($request->id)->and($notice->data['brand'])->toBe('Canadian Protein')->and($notice->read_at)->toBeNull()
        ->and($other->notifications()->count())->toBe(0);

    // Opening the request counts as having seen the notice.
    Livewire::test(RequestPage::class, ['brand' => $this->brand, 'request' => $request->fresh()])->assertOk();
    expect($notice->fresh()->read_at)->not->toBeNull();

    plannerPlans($llm);
    $this->requests->answer($request->fresh(), ['dates' => 'Saturday'], $this->admin);

    $plan = $this->admin->unreadNotifications()->sole();
    expect($plan->data['title'])->toBe('The plan for "Whey weekend" is ready')->and($plan->data['body'])->toStartWith('Read it over, then press "Confirm and run", for about $');
});

it('emails the events the person chose, in the app only otherwise, and tells them when planning fails', function () {
    Notification::fake();
    $llm = plannerAsks();

    $request = $this->requests->create($this->brand, noticeInput(), $this->admin);
    $this->requests->plan($request);
    Notification::assertSentTo($this->admin, RequestNotice::class, fn (RequestNotice $n, array $channels) => $n->event === RequestNotice::NEEDS_YOU && $channels === ['database', 'mail']);

    // Unticked under Your account: the notice still shows, nothing is emailed.
    app(Settings::class)->set('notify.email', [RequestNotice::DONE], SettingScope::User, $this->admin->id, $this->admin);
    plannerPlans($llm);
    $this->requests->answer($request->fresh(), ['dates' => 'Saturday'], $this->admin);
    Notification::assertSentTo($this->admin, RequestNotice::class, fn (RequestNotice $n, array $channels) => str_contains($n->title, 'is ready') && $channels === ['database']);

    // The planner failing is for the owner too.
    $failing = $this->requests->create($this->brand, noticeInput(), $this->admin);
    $llm->push(fn () => throw new RuntimeException('Claude is down.'));
    $this->requests->plan($failing);
    expect($failing->fresh()->status)->toBe(CreativeRequest::FAILED);
    Notification::assertSentTo($this->admin, RequestNotice::class, fn (RequestNotice $n) => $n->event === RequestNotice::NEEDS_YOU && $n->title === 'The planner couldn\'t finish "Marketing image for a sale on whey protein isolate, 20% off..."' && $n->body === 'Claude is down.');

    // The email waits, and is dropped when the notice was seen meanwhile.
    app(Settings::class)->set('notify.email_delay', 5, SettingScope::Platform, 0, $this->admin);
    $notice = new RequestNotice(RequestNotice::DONE, 't', 'b', route('notices'), 1, $this->brand->id, 'Canadian Protein');
    $notice->id = noticeFor($this->admin, read: true)->id;
    expect($notice->withDelay($this->admin, 'mail')?->gt(now()->addMinutes(4)))->toBeTrue()->and($notice->withDelay($this->admin, 'database'))->toBeNull()
        ->and($notice->shouldSend($this->admin, 'mail'))->toBeFalse()->and($notice->shouldSend($this->admin, 'database'))->toBeTrue();
    $notice->id = noticeFor($this->admin)->id;
    expect($notice->shouldSend($this->admin, 'mail'))->toBeTrue()
        ->and($notice->toMail($this->admin)->subject)->toBe('Canadian Protein: t');
});

it('shows the bell with the unread count and the latest notices, opens one as read, and marks them all read', function () {
    $mine = noticeFor($this->admin, ['title' => 'The plan for "Whey weekend" is ready', 'url' => route('account')]);
    noticeFor($this->admin, ['title' => 'Older and read'], read: true);
    $theirs = noticeFor(User::factory()->withRole(Role::Creator)->create());

    $this->get(route('account'))->assertOk()->assertSee('Notices, 1 unread');

    $bell = Livewire::test(Bell::class)->assertSee('Notices, 1 unread')->assertDontSee('Whey weekend')
        ->call('show')->assertSee('The plan for "Whey weekend" is ready')->assertSee('Older and read')->assertSee('Mark all as read');

    // Someone else's notice can't be opened; one's own is marked read and leads to the request.
    $bell->call('open', $theirs->id)->assertNoRedirect();
    expect($theirs->fresh()->read_at)->toBeNull();
    $bell->call('open', $mine->id)->assertRedirect(route('account'));
    expect($mine->fresh()->read_at)->not->toBeNull();

    noticeFor($this->admin);
    noticeFor($this->admin);
    Livewire::test(Bell::class)->assertSee('Notices, 2 unread')->call('readAll');
    expect($this->admin->unreadNotifications()->count())->toBe(0)->and($theirs->fresh()->read_at)->toBeNull();

    // The full list.
    Livewire::test(NoticesPage::class)->assertOk()->assertSee('"Whey weekend" is ready')->assertSee('Older and read')->assertDontSee('Mark all as read');
    $this->get(route('notices'))->assertOk()->assertSee('Everything that happened to your requests');
});

it('lets each person choose what they are emailed about on their account, and nobody else for them', function () {
    $this->get(route('account'))->assertOk()->assertSee('Email about')->assertSee('A run has finished, or failed');
    $this->get(route('settings'))->assertOk()->assertSee('Email about')->assertSee('Wait before emailing');

    Livewire::test(SettingsForm::class, ['scope' => SettingScope::User, 'scopeId' => $this->admin->id, 'section' => 'Notifications'])
        ->assertSet('values.notify__email', [RequestNotice::NEEDS_YOU, RequestNotice::DONE])
        ->set('values.notify__email', [RequestNotice::CHANGES])->call('save', 'notify__email')->assertHasNoErrors();
    expect(app(Settings::class)->get('notify.email', null, $this->admin->id))->toBe([RequestNotice::CHANGES])
        ->and(app(Settings::class)->get('notify.email'))->toBe([RequestNotice::NEEDS_YOU, RequestNotice::DONE]);

    $other = User::factory()->withRole(Role::Creator)->create();
    Livewire::test(SettingsForm::class, ['scope' => SettingScope::User, 'scopeId' => $other->id, 'section' => 'Notifications'])
        ->assertSee('your role can\'t change them', false)
        ->set('values.notify__email', [])->call('save', 'notify__email')->assertForbidden();
});
