<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of anyone whose account was turned off while they were
 * signed in, rather than waiting for the session to expire.
 */
class SignOutDisabledUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDisabled()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'google' => 'This account has been turned off. Ask an admin if you need access again.',
            ]);
        }

        return $next($request);
    }
}
