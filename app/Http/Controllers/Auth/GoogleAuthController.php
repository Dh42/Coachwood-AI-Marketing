<?php

namespace App\Http\Controllers\Auth;

use App\Auth\CompanyDomains;
use App\Auth\SignIn;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class GoogleAuthController extends Controller
{
    public function redirect(CompanyDomains $domains): SymfonyRedirect
    {
        $allowed = $domains->all();

        // Google's hd hint takes one domain or "*" (any Workspace account), never
        // a list. It only narrows the account picker; the real check is in callback().
        return Socialite::driver('google')
            ->with(['hd' => count($allowed) === 1 ? $allowed[0] : '*', 'prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(CompanyDomains $domains, SignIn $signIn): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return $this->fail('Google sign-in timed out or was interrupted. Please try again.');
        }

        $email = Str::lower($google->getEmail() ?? '');

        if ($email === '' || ! ($google->getRaw()['email_verified'] ?? false)) {
            SignIn::blocked($email, 'email not verified by Google');

            return $this->fail('Google didn\'t confirm that email address. Try a different account.');
        }

        $user = User::where('google_id', $google->getId())->first();
        $invited = Invitation::open()->where('email', $email)->where('expires_at', '>', now())->exists();

        // An account that signs in another way is only joined to this Google account when the
        // email is on a company domain, or the person was invited with it.
        if (! $user && ($existing = User::where('email', $email)->first())) {
            $wasInvited = $invited || Invitation::where('email', $email)->whereNotNull('accepted_at')->exists();

            if ($existing->google_id || ! ($domains->includes($email) || $wasInvited)) {
                SignIn::blocked($email, 'Google account not linked to the existing account');

                return $this->fail("{$email} already has an account here that signs in with a password. Sign in with your email and password instead.");
            }

            $user = $existing;
        }

        if (! $user && ! $invited && ! $domains->includes($email)) {
            SignIn::blocked($email, 'domain not allowed');

            return $this->fail("{$email} isn't a company account. Sign in with your company Google account, or ask an admin for an invitation.");
        }

        if ($user?->isDisabled()) {
            SignIn::blocked($email, 'account disabled');

            return $this->fail('This account has been turned off. Ask an admin if you need access again.');
        }

        $user ??= User::create([
            'name' => $google->getName() ?: $email,
            'email' => $email,
            'email_verified_at' => now(),
        ]);

        if (! $user->google_id) {
            $user->forceFill(['google_id' => $google->getId()])->save();
        }

        $signIn->complete($user, 'Google');

        return redirect()->intended(route('home'));
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }
}
