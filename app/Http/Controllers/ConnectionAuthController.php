<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use App\Plugins\ConnectionMethod;
use App\Plugins\Connections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign-in flow for OAuth connections (Zendesk). One callback URL serves
 * every plugin. The state value is tied to this person's session and one
 * connection, works once, and expires after a few minutes; a PKCE verifier
 * travels with it.
 */
class ConnectionAuthController extends Controller
{
    /** How long someone has to approve access on the service's own screen. */
    private const STATE_MINUTES = 15;

    public function start(Connection $connection, Request $request): RedirectResponse
    {
        $plugin = $connection->plugin();
        abort_unless($plugin?->connectionMethod() === ConnectionMethod::OAuth, 404);

        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put("connection_oauth.{$state}", [
            'connection_id' => $connection->id,
            'verifier' => $verifier,
            'expires_at' => now()->addMinutes(self::STATE_MINUTES)->timestamp,
        ]);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        try {
            $url = $plugin->authorizationUrl($connection, $state, route('connections.oauth.callback'), $challenge);
        } catch (Throwable $e) {
            return redirect()->route('connections.show', $connection)->withErrors(['oauth' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function callback(Request $request, Connections $connections): RedirectResponse
    {
        $pending = $request->session()->pull('connection_oauth.'.$request->query('state'));
        $connection = $pending ? Connection::find($pending['connection_id']) : null;

        if (! $connection || $pending['expires_at'] < now()->timestamp) {
            return redirect()->route($connection ? 'connections.show' : 'connections', $connection ? [$connection] : [])
                ->withErrors(['oauth' => 'The sign-in link expired or was already used. Start again from the connection.']);
        }

        if ($request->filled('error')) {
            return $this->fail($connection, $request->query('error') === 'access_denied'
                ? 'The connection wasn\'t completed because access was denied. Try again when you\'re ready.'
                : 'Access wasn\'t approved: '.$request->query('error_description', $request->query('error')));
        }

        try {
            $secrets = $connection->plugin()->completeAuthorization($connection, $request, route('connections.oauth.callback'), $pending['verifier']);
        } catch (Throwable $e) {
            report($e);

            return $this->fail($connection, $e->getMessage());
        }

        $connections->authorized($connection, $secrets, $request->user());

        return redirect()->route('connections.show', $connection)->with('status', $connection->isConnected()
            ? "Connected to {$connection->plugin()->accountLabel($connection)}."
            : 'Signed in, but the check afterwards failed. See the message below.');
    }

    private function fail(Connection $connection, string $message): RedirectResponse
    {
        return redirect()->route('connections.show', $connection)->withErrors(['oauth' => $message]);
    }
}
