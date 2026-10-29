<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Reset password')]
class ForgotPassword extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    public bool $sent = false;

    public function send(): void
    {
        $this->validate();

        $key = 'password-reset|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, (int) app(Settings::class)->get('auth.reset_requests_per_ip'))) {
            throw ValidationException::withMessages(['email' => 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        RateLimiter::hit($key);

        $user = User::where('email', Str::lower($this->email))->first();

        // Google accounts have no password to reset. The reply is the same
        // either way, so the form doesn't reveal who has an account.
        if ($user?->usesPassword() && ! $user->isDisabled()) {
            Password::sendResetLink(['email' => $user->email]);
        }

        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
