<?php

use App\Auth\Accounts;
use App\Livewire\Account;
use App\Livewire\Auth\Login;
use App\Models\User;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;

it('keeps people signed in for the set number of days', function () {
    $admin = User::factory()->create();
    app(Settings::class)->set('auth.remember_days', 10, SettingScope::Platform, 0, $admin);
    User::factory()->create(['email' => 'contractor@gmail.com', 'password' => 'correct horse battery']);

    Livewire::test(Login::class)->set('email', 'contractor@gmail.com')->set('password', 'correct horse battery')->call('login');

    $cookie = Cookie::queued(Auth::guard('web')->getRecallerName());
    expect($cookie->getExpiresTime())->toBeBetween(now()->addDays(10)->subMinute()->timestamp, now()->addDays(10)->addMinute()->timestamp);
});

it('signs out every other device when the password changes', function () {
    $user = User::factory()->create(['password' => 'correct horse battery']);
    $oldToken = 'old-remember-token';
    $user->forceFill(['remember_token' => $oldToken])->save();
    $this->actingAs($user);

    // Another device: a session that saw the old password.
    $otherDevice = ['password_hash_web' => Auth::guard('web')->hashPasswordForCookie($user->password)];
    $this->withSession($otherDevice)->get(route('account'))->assertOk();

    Livewire::test(Account::class)
        ->set('current_password', 'correct horse battery')
        ->set('password', 'a brand new passphrase')
        ->set('password_confirmation', 'a brand new passphrase')
        ->call('changePassword')
        ->assertHasNoErrors();

    expect($user->fresh()->remember_token)->not->toBe($oldToken);

    $this->actingAs($user->fresh());
    $this->withSession($otherDevice)->get(route('account'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ends "stay signed in" when an account is turned off', function () {
    $admin = signInAs();
    $user = User::factory()->create();
    $user->forceFill(['remember_token' => 'old-remember-token'])->save();

    app(Accounts::class)->disable($user, $admin);

    expect($user->fresh()->remember_token)->not->toBe('old-remember-token');
});

it('stops other sites framing the platform\'s pages', function () {
    $page = $this->get(route('login'))->assertOk();

    expect($page->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($page->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($page->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($page->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('tells browsers to use only HTTPS when the platform is served over it', function () {
    config(['app.url' => 'https://cs.example.com', 'security.hsts_seconds' => 31536000]);

    expect($this->get(route('login'))->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');

    config(['security.hsts_seconds' => 0]);
    expect($this->get(route('login'))->headers->has('Strict-Transport-Security'))->toBeFalse();
});
