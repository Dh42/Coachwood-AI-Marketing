<?php

use App\Auth\Invitations;
use App\Auth\Role;
use App\Models\Invitation;
use App\Models\User;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Spatie\Activitylog\Models\Activity;

function fakeGoogle(string $email, string $id = '1001', bool $verified = true, string $name = 'Test Person'): void
{
    $googleUser = (new GoogleUser)
        ->setRaw(['email_verified' => $verified])
        ->map(['id' => $id, 'name' => $name, 'email' => $email]);

    Socialite::shouldReceive('driver->user')->andReturn($googleUser);
}

it('creates an account with no access for a new company-domain user', function () {
    fakeGoogle('new.person@biosteel.com', name: 'New Person');

    $this->get(route('google.callback'))->assertRedirect(route('home'));

    $user = User::where('email', 'new.person@biosteel.com')->firstOrFail();
    expect($user->google_id)->toBe('1001')
        ->and($user->password)->toBeNull()
        ->and($user->roleAssignments)->toBeEmpty()
        ->and($user->last_login_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('treats company domains case-insensitively', function () {
    fakeGoogle('Mixed.Case@CanadianProtein.com');

    $this->get(route('google.callback'))->assertRedirect(route('home'));

    expect(User::where('email', 'mixed.case@canadianprotein.com')->exists())->toBeTrue();
});

it('blocks a Google account outside the company domains', function () {
    fakeGoogle('someone@gmail.com');

    $this->get(route('google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('google');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('lets an invited outside Google account in and applies the invitation', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    app(Invitations::class)->invite('contractor@gmail.com', Role::Viewer, $admin);

    fakeGoogle('contractor@gmail.com');

    $this->get(route('google.callback'))->assertRedirect(route('home'));

    $user = User::where('email', 'contractor@gmail.com')->firstOrFail();
    expect($user->platformRole())->toBe(Role::Viewer)
        ->and(Invitation::first()->accepted_at)->not->toBeNull();
});

it('applies an open invitation when a company user signs in', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    app(Invitations::class)->invite('bhavik@biosteel.com', Role::Creator, $admin);

    fakeGoogle('bhavik@biosteel.com');
    $this->get(route('google.callback'));

    expect(User::where('email', 'bhavik@biosteel.com')->first()->platformRole())->toBe(Role::Creator);
});

it('refuses an email Google has not verified', function () {
    fakeGoogle('unverified@biosteel.com', verified: false);

    $this->get(route('google.callback'))->assertSessionHasErrors('google');

    $this->assertGuest();
});

it('refuses a turned-off account', function () {
    User::factory()->google()->disabled()->create(['email' => 'gone@biosteel.com', 'google_id' => '1001']);
    fakeGoogle('gone@biosteel.com');

    $this->get(route('google.callback'))->assertSessionHasErrors('google');

    $this->assertGuest();
});

it('links Google to an existing account with the same email', function () {
    $user = User::factory()->create(['email' => 'lesley@biosteel.com', 'google_id' => null]);
    fakeGoogle('lesley@biosteel.com', id: '555');

    $this->get(route('google.callback'));

    expect($user->fresh()->google_id)->toBe('555');
    $this->assertAuthenticatedAs($user);
});

it('doesn\'t join a Google account to an outside password account with the same email', function () {
    $user = User::factory()->create(['email' => 'someone@gmail.com', 'google_id' => null]);
    fakeGoogle('someone@gmail.com', id: '556');

    $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('google');

    expect($user->fresh()->google_id)->toBeNull();
    $this->assertGuest();
});

it('joins an invited outside account to Google', function () {
    $user = User::factory()->create(['email' => 'contractor@gmail.com', 'google_id' => null]);
    Invitation::create(['email' => 'contractor@gmail.com', 'role' => 'viewer', 'token_hash' => 'x', 'expires_at' => now()->addDay(), 'accepted_at' => now()]);
    fakeGoogle('contractor@gmail.com', id: '557');

    $this->get(route('google.callback'));

    expect($user->fresh()->google_id)->toBe('557');
    $this->assertAuthenticatedAs($user);
});

it('refuses a second Google account for an email already joined to one', function () {
    User::factory()->create(['email' => 'lesley@biosteel.com', 'google_id' => '555']);
    fakeGoogle('lesley@biosteel.com', id: '999');

    $this->get(route('google.callback'))->assertSessionHasErrors('google');
    $this->assertGuest();
});

it('sends the person back to sign in if Google state is lost', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

    $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('google');
});

it('uses the saved company domains setting', function () {
    $admin = User::factory()->admin()->create();
    app(Settings::class)->set('auth.allowed_domains', ['example.org'], SettingScope::Platform, 0, $admin);
    fakeGoogle('person@biosteel.com');

    $this->get(route('google.callback'))->assertSessionHasErrors('google');
});

it('logs successful and blocked sign-ins to the audit trail', function () {
    fakeGoogle('someone@gmail.com');
    $this->get(route('google.callback'));

    expect(Activity::where('event', 'sign_in_blocked')->exists())->toBeTrue();
});
