<?php

use App\Auth\Invitations;
use App\Auth\Role;
use App\Auth\Roles;
use App\Livewire\Auth\AcceptInvitation;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create(['name' => 'Lesley']);
});

/**
 * Sends an invitation and returns the plain token from the email.
 */
function inviteAndGetToken(string $email, Role $role = Role::Viewer): string
{
    app(Invitations::class)->invite($email, $role, test()->admin);

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function ($n, $channels, $notifiable) use ($email, &$token) {
        if ($notifiable->routes['mail'] === $email) {
            $token = $n->token;
        }

        return true;
    });

    return $token;
}

it('emails a link and stores only a hash of the token', function () {
    $token = inviteAndGetToken('contractor@gmail.com');

    $invitation = Invitation::sole();
    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->inviter->is($this->admin))->toBeTrue();
});

it('expires invitations after the configured number of days', function () {
    app(Settings::class)->set('invitations.expiry_days', 3, SettingScope::Platform, 0, $this->admin);

    $this->freezeTime(function () {
        app(Invitations::class)->invite('contractor@gmail.com', Role::Viewer, $this->admin);

        expect(Invitation::sole()->expires_at->toDateTimeString())->toBe(now()->addDays(3)->toDateTimeString());
    });
});

it('tells company invitees to use Google and others to set a password', function () {
    $this->get(route('invitations.show', inviteAndGetToken('clark@biosteel.com')))
        ->assertOk()->assertSee('Continue with Google');

    $this->get(route('invitations.show', inviteAndGetToken('contractor@gmail.com')))
        ->assertOk()->assertSee('Create account');
});

it('lets an outside invitee create a password account and get their role', function () {
    $token = inviteAndGetToken('contractor@gmail.com', Role::Creator);

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('name', 'Casey Contractor')
        ->set('password', 'a long enough password')
        ->set('password_confirmation', 'a long enough password')
        ->call('createAccount')
        ->assertRedirect(route('home'));

    $user = User::where('email', 'contractor@gmail.com')->sole();
    expect($user->platformRole())->toBe(Role::Creator)
        ->and($user->usesPassword())->toBeTrue()
        ->and(Invitation::sole()->accepted_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('requires a password of at least 12 characters', function () {
    $token = inviteAndGetToken('contractor@gmail.com');

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('name', 'Casey')
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('createAccount')
        ->assertHasErrors('password');
});

it('refuses to create a password account for a company address', function () {
    $token = inviteAndGetToken('clark@biosteel.com');

    Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('name', 'Clark')
        ->set('password', 'a long enough password')
        ->set('password_confirmation', 'a long enough password')
        ->call('createAccount')
        ->assertForbidden();
});

it('explains expired, cancelled, used and unknown links', function () {
    $expired = inviteAndGetToken('a@gmail.com');
    Invitation::where('email', 'a@gmail.com')->update(['expires_at' => now()->subMinute()]);
    $this->get(route('invitations.show', $expired))->assertSee('This invitation has expired');

    $cancelled = inviteAndGetToken('b@gmail.com');
    app(Invitations::class)->revoke(Invitation::where('email', 'b@gmail.com')->sole(), $this->admin);
    $this->get(route('invitations.show', $cancelled))->assertSee("link doesn't work", escape: false);

    $used = inviteAndGetToken('c@gmail.com');
    Invitation::where('email', 'c@gmail.com')->update(['accepted_at' => now()]);
    $this->get(route('invitations.show', $used))->assertSee('already been used');

    $this->get(route('invitations.show', 'not-a-real-token'))->assertSee("link doesn't work", escape: false);
});

it('does not apply an expired invitation at sign-in', function () {
    inviteAndGetToken('clark@biosteel.com');
    Invitation::query()->update(['expires_at' => now()->subMinute()]);
    $user = User::factory()->google()->create(['email' => 'clark@biosteel.com']);

    app(Invitations::class)->acceptOpenFor($user);

    expect($user->fresh()->platformRole())->toBeNull();
});

it('replaces an earlier open invitation to the same person', function () {
    $first = inviteAndGetToken('contractor@gmail.com', Role::Viewer);
    inviteAndGetToken('contractor@gmail.com', Role::Creator);

    expect(Invitation::open()->count())->toBe(1)
        ->and(Invitation::open()->sole()->role)->toBe(Role::Creator);
    $this->get(route('invitations.show', $first))->assertSee("link doesn't work", escape: false);
});

it('refuses to invite someone who already has access', function () {
    User::factory()->withRole(Role::Viewer)->create(['email' => 'clark@biosteel.com']);

    app(Invitations::class)->invite('clark@biosteel.com', Role::Admin, $this->admin);
})->throws(ValidationException::class);

it('resends with a new link that replaces the old one', function () {
    $old = inviteAndGetToken('contractor@gmail.com');
    $invitation = Invitation::sole();

    app(Invitations::class)->resend($invitation, $this->admin);

    expect(Invitation::findByToken($old))->toBeNull()
        ->and($invitation->fresh()->isUsable())->toBeTrue();
});

it('does not downgrade a role given after the invitation was sent', function () {
    inviteAndGetToken('clark@biosteel.com', Role::Viewer);
    $user = User::factory()->google()->create(['email' => 'clark@biosteel.com']);
    app(Roles::class)->assign($user, Role::Admin, by: $this->admin);

    app(Invitations::class)->acceptOpenFor($user);

    expect($user->fresh()->platformRole())->toBe(Role::Admin);
});

it('applies the invitation straight away when the invitee is already signed in', function () {
    $token = inviteAndGetToken('clark@biosteel.com', Role::Viewer);
    $user = User::factory()->google()->create(['email' => 'clark@biosteel.com']);
    $this->actingAs($user);

    Livewire::test(AcceptInvitation::class, ['token' => $token])->assertRedirect(route('home'));

    expect($user->fresh()->platformRole())->toBe(Role::Viewer);
});

it('warns when someone else is signed in', function () {
    $token = inviteAndGetToken('clark@biosteel.com');
    signInAs(Role::Viewer, ['email' => 'someone.else@biosteel.com']);

    $this->get(route('invitations.show', $token))->assertSee('This invitation is for someone else');
});
