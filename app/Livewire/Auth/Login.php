<?php

namespace App\Livewire\Auth;

use App\Auth\SignIn;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Sign in')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public function login(SignIn $signIn)
    {
        $this->validate();

        $throttleKey = Str::lower($this->email).'|'.request()->ip();
        $addressKey = 'sign-in-address|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5) || RateLimiter::tooManyAttempts($addressKey, (int) app(Settings::class)->get('auth.sign_in_attempts_per_ip'))) {
            $seconds = max(RateLimiter::availableIn($throttleKey), RateLimiter::availableIn($addressKey));

            throw ValidationException::withMessages([
                'email' => "Too many attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $user = User::where('email', Str::lower($this->email))->first();

        if (! $user?->usesPassword() || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($throttleKey);
            RateLimiter::hit($addressKey);
            SignIn::blocked($this->email, 'wrong email or password');

            throw ValidationException::withMessages([
                'email' => 'That email and password don\'t match. Company accounts sign in with Google instead.',
            ]);
        }

        if ($user->isDisabled()) {
            SignIn::blocked($this->email, 'account disabled');

            throw ValidationException::withMessages([
                'email' => 'This account has been turned off. Ask an admin if you need access again.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $signIn->complete($user, 'password');

        return $this->redirectIntended(route('home'), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
