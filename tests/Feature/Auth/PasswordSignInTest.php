<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Settings\Settings;
use App\Settings\SettingScope;
use Livewire\Livewire;

it('shows the sign-in page with Google and password options', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with Google')
        ->assertSee('Forgot your password?');
});

it('signs in an invited outside user with their password', function () {
    $user = User::factory()->create(['email' => 'contractor@gmail.com', 'password' => 'correct horse battery']);

    Livewire::test(Login::class)
        ->set('email', 'contractor@gmail.com')
        ->set('password', 'correct horse battery')
        ->call('login')
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects a wrong password with a helpful message', function () {
    User::factory()->create(['email' => 'contractor@gmail.com', 'password' => 'correct horse battery']);

    Livewire::test(Login::class)
        ->set('email', 'contractor@gmail.com')
        ->set('password', 'nope')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('never lets a Google-only account sign in by password', function () {
    User::factory()->google()->create(['email' => 'lesley@biosteel.com']);

    Livewire::test(Login::class)
        ->set('email', 'lesley@biosteel.com')
        ->set('password', 'anything')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('refuses a turned-off account even with the right password', function () {
    User::factory()->disabled()->create(['email' => 'old@gmail.com', 'password' => 'correct horse battery']);

    Livewire::test(Login::class)
        ->set('email', 'old@gmail.com')
        ->set('password', 'correct horse battery')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

it('locks out repeated wrong guesses', function () {
    User::factory()->create(['email' => 'contractor@gmail.com', 'password' => 'correct horse battery']);

    $component = Livewire::test(Login::class)->set('email', 'contractor@gmail.com')->set('password', 'wrong');

    foreach (range(1, 5) as $_) {
        $component->call('login');
    }

    $component->set('password', 'correct horse battery')->call('login')->assertHasErrors('email');
    expect($component->errors()->first('email'))->toContain('Too many attempts');
    $this->assertGuest();
});

it('stops one address trying a password on many emails', function () {
    app(Settings::class)->set('auth.sign_in_attempts_per_ip', 5, SettingScope::Platform, 0, User::factory()->create());
    User::factory()->create(['email' => 'contractor@gmail.com', 'password' => 'correct horse battery']);

    foreach (range(1, 5) as $i) {
        Livewire::test(Login::class)->set('email', "person{$i}@gmail.com")->set('password', 'Summer2026!')->call('login');
    }

    $component = Livewire::test(Login::class)->set('email', 'contractor@gmail.com')->set('password', 'correct horse battery')->call('login');
    expect($component->errors()->first('email'))->toContain('Too many attempts');
    $this->assertGuest();
});

it('signs out', function () {
    signInAs();

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
});
