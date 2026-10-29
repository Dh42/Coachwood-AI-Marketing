<?php

namespace App\Livewire\Auth;

use App\Auth\CompanyDomains;
use App\Auth\Invitations;
use App\Auth\SignIn;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Accept invitation')]
class AcceptInvitation extends Component
{
    #[Locked]
    public string $token;

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token, Invitations $invitations)
    {
        $this->token = $token;
        $invitation = $this->invitation();

        // Already signed in as the invited person: apply it straight away.
        if ($invitation?->isUsable() && Auth::user()?->email === $invitation->email) {
            $invitations->acceptOpenFor(Auth::user());

            return $this->redirectRoute('home');
        }
    }

    public function createAccount(SignIn $signIn, CompanyDomains $domains)
    {
        $invitation = $this->invitation();

        abort_unless($invitation?->isUsable() && $this->state($invitation, $domains) === 'set-password', 403);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = DB::transaction(fn () => User::create([
            'name' => $this->name,
            'email' => $invitation->email,
            'password' => $this->password,
            'email_verified_at' => now(),
        ]));

        $signIn->complete($user, 'password');

        return $this->redirectRoute('home');
    }

    public function render(CompanyDomains $domains)
    {
        $invitation = $this->invitation();

        return view('livewire.auth.accept-invitation', [
            'invitation' => $invitation,
            'state' => $this->state($invitation, $domains),
        ]);
    }

    private function invitation(): ?Invitation
    {
        return Invitation::findByToken($this->token);
    }

    /**
     * What the person needs to do next, in the order it's checked.
     */
    private function state(?Invitation $invitation, CompanyDomains $domains): string
    {
        return match (true) {
            $invitation === null || $invitation->revoked_at !== null => 'invalid',
            $invitation->accepted_at !== null => 'accepted',
            $invitation->expires_at->isPast() => 'expired',
            Auth::check() && Auth::user()->email !== $invitation->email => 'wrong-account',
            $domains->includes($invitation->email) => 'google',
            User::where('email', $invitation->email)->exists() => 'sign-in',
            default => 'set-password',
        };
    }
}
