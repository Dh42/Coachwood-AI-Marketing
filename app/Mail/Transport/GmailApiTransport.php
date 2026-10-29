<?php

namespace App\Mail\Transport;

use App\Models\GmailConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends mail through the Gmail API as the connected account, using the same
 * Google OAuth client as sign-in. Ported from CompanyTools.
 */
class GmailApiTransport extends AbstractTransport
{
    public const TOKEN_CACHE_KEY = 'gmail_api_access_token';

    protected function doSend(SentMessage $message): void
    {
        $raw = rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '=');

        $response = Http::withToken($this->accessToken())
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $raw]);

        if ($response->failed()) {
            // A revoked or expired token: drop it so the next try fetches a new one.
            Cache::forget(self::TOKEN_CACHE_KEY);

            throw new RuntimeException('Gmail couldn\'t send the email: '.$response->body());
        }
    }

    protected function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $connection = GmailConnection::current()
                ?? throw new RuntimeException('No Gmail account is connected for sending email. An admin can connect one under Settings.');

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if ($response->failed()) {
                throw new RuntimeException('Google refused the saved Gmail connection. Reconnect the sending account under Settings. ('.$response->body().')');
            }

            return $response->json('access_token');
        });
    }

    public function __toString(): string
    {
        return 'gmail+api';
    }
}
