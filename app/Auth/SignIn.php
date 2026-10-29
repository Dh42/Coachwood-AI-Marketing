<?php

namespace App\Auth;

use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Facades\Auth;

/**
 * The steps every successful sign-in shares, whichever method was used.
 */
class SignIn
{
    public function __construct(private Invitations $invitations, private Settings $settings) {}

    public function complete(User $user, string $method): void
    {
        $user->forceFill(['last_login_at' => now()])->save();

        $this->invitations->acceptOpenFor($user);

        Auth::guard('web')->setRememberDuration((int) $this->settings->get('auth.remember_days') * 24 * 60);
        Auth::login($user, remember: true);
        session()->regenerate();

        activity('auth')
            ->causedBy($user)
            ->event('signed_in')
            ->withProperties(['method' => $method, 'ip' => request()->ip()])
            ->log("Signed in with {$method}");
    }

    public static function blocked(string $email, string $reason): void
    {
        activity('auth')
            ->event('sign_in_blocked')
            ->withProperties(['email' => $email, 'reason' => $reason, 'ip' => request()->ip()])
            ->log("Sign-in blocked for {$email}: {$reason}");
    }
}
