<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Your account')]
class Account extends Component
{
    public string $name = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
    }

    public function saveName(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        Auth::user()->update(['name' => $this->name]);

        $this->dispatch('saved', message: 'Name updated.');
    }

    public function changePassword(): void
    {
        $user = Auth::user();

        abort_unless($user->usesPassword(), 403);

        $this->validate([
            'current_password' => ['required', fn ($attr, $value, $fail) => Hash::check($value, $user->password) || $fail('That isn\'t your current password.')],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        // A new password signs out every other device (AuthenticateSession), and ends "stay signed in" on them.
        $user->forceFill(['password' => $this->password])->setRememberToken(Str::random(60));
        $user->save();
        Auth::guard('web')->logoutOtherDevices($this->password);
        $this->reset('current_password', 'password', 'password_confirmation');

        activity('auth')->causedBy($user)->event('password_changed')->log('Changed their password');

        $this->dispatch('saved', message: 'Password changed.');
    }

    public function render()
    {
        return view('livewire.account', ['user' => Auth::user()->load('roleAssignments')]);
    }
}
