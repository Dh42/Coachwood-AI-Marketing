<?php

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

it('sends a reset link to a password account', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'contractor@gmail.com']);

    Livewire::test(ForgotPassword::class)->set('email', 'contractor@gmail.com')->call('send')->assertSet('sent', true);

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('gives the same answer but sends nothing for Google accounts and unknown emails', function () {
    Notification::fake();
    User::factory()->google()->create(['email' => 'lesley@biosteel.com']);

    Livewire::test(ForgotPassword::class)->set('email', 'lesley@biosteel.com')->call('send')->assertSet('sent', true);
    Livewire::test(ForgotPassword::class)->set('email', 'nobody@gmail.com')->call('send')->assertSet('sent', true);

    Notification::assertNothingSent();
});

it('changes the password with a valid token', function () {
    $user = User::factory()->create(['email' => 'contractor@gmail.com']);
    $token = Password::createToken($user);

    Livewire::test(ResetPassword::class, ['token' => $token])
        ->set('email', 'contractor@gmail.com')
        ->set('password', 'a brand new password')
        ->set('password_confirmation', 'a brand new password')
        ->call('resetPassword')
        ->assertRedirect(route('login'));

    expect(Hash::check('a brand new password', $user->fresh()->password))->toBeTrue();
});

it('rejects a bad token', function () {
    User::factory()->create(['email' => 'contractor@gmail.com']);

    Livewire::test(ResetPassword::class, ['token' => 'wrong'])
        ->set('email', 'contractor@gmail.com')
        ->set('password', 'a brand new password')
        ->set('password_confirmation', 'a brand new password')
        ->call('resetPassword')
        ->assertHasErrors('email');
});

it('limits how many reset emails one address can ask for', function () {
    Notification::fake();
    User::factory()->create(['email' => 'contractor@gmail.com', 'password' => 'correct horse battery']);

    foreach (range(1, 5) as $_) {
        Livewire::test(ForgotPassword::class)->set('email', 'someone@gmail.com')->call('send')->assertHasNoErrors();
    }

    Livewire::test(ForgotPassword::class)->set('email', 'contractor@gmail.com')->call('send')->assertHasErrors('email');
    Notification::assertNothingSent();
});
