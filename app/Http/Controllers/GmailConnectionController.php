<?php

namespace App\Http\Controllers;

use App\Mail\Transport\GmailApiTransport;
use App\Models\GmailConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * Connects the Gmail account the platform sends email from. Uses the same
 * Google OAuth client as sign-in, asking only for permission to send.
 */
class GmailConnectionController extends Controller
{
    public function connect(): SymfonyRedirect
    {
        return Socialite::driver('google')
            ->redirectUrl(route('settings.email.callback'))
            ->scopes(['https://www.googleapis.com/auth/gmail.send'])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->redirectUrl(route('settings.email.callback'))->user();
        } catch (InvalidStateException) {
            return $this->back('Connecting the Gmail account was interrupted. Please try again.');
        }

        if (! $google->refreshToken) {
            return $this->back('Google didn\'t give lasting access. In that Google account, remove this app under Security → Third-party access, then connect again.');
        }

        DB::transaction(function () use ($google, $request) {
            GmailConnection::query()->delete();
            GmailConnection::create([
                'email' => $google->getEmail(),
                'refresh_token' => $google->refreshToken,
                'connected_by' => $request->user()->id,
            ]);
        });

        Cache::forget(GmailApiTransport::TOKEN_CACHE_KEY);

        activity('settings')
            ->causedBy($request->user())
            ->event('email_connected')
            ->withProperties(['email' => $google->getEmail()])
            ->log("Connected {$google->getEmail()} for sending email");

        return redirect()->route('settings')->with('status', "Email will now be sent from {$google->getEmail()}.");
    }

    private function back(string $error): RedirectResponse
    {
        return redirect()->route('settings')->withErrors(['email_connection' => $error]);
    }
}
