<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Choose a new password')]
class ResetPassword extends Component
{
    #[Locked]
    public string $token;

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword()
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $status = Password::reset(
            ['email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
                $user->save();

                activity('auth')->causedBy($user)->event('password_reset')->log('Reset their password');
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', 'This reset link is invalid or has expired. Ask for a new one.');

            return;
        }

        session()->flash('status', 'Your password has been changed. Sign in with it now.');

        return $this->redirectRoute('login');
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
