<?php

use App\Auth\Role;
use App\Livewire\Settings\EmailConnection;
use App\Mail\Transport\GmailApiTransport;
use App\Models\GmailConnection;
use App\Notifications\TestEmailNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Livewire\Livewire;

it('saves the connected Gmail account, encrypted, replacing any earlier one', function () {
    $admin = signInAs(Role::Admin);
    GmailConnection::create(['email' => 'old@biosteel.com', 'refresh_token' => 'old']);

    $google = (new GoogleUser)->map(['id' => '1', 'email' => 'support@biosteel.com'])->setRefreshToken('refresh-123');
    Socialite::shouldReceive('driver->redirectUrl->user')->andReturn($google);

    $this->get(route('settings.email.callback'))->assertRedirect(route('settings'));

    $connection = GmailConnection::sole();
    expect($connection->email)->toBe('support@biosteel.com')
        ->and($connection->refresh_token)->toBe('refresh-123')
        ->and(DB::table('gmail_connections')->value('refresh_token'))->not->toContain('refresh-123')
        ->and($connection->connected_by)->toBe($admin->id);
});

it('explains when Google gives no lasting access', function () {
    signInAs(Role::Admin);
    Socialite::shouldReceive('driver->redirectUrl->user')->andReturn((new GoogleUser)->map(['id' => '1', 'email' => 'a@biosteel.com']));

    $this->get(route('settings.email.callback'))->assertSessionHasErrors('email_connection');

    expect(GmailConnection::count())->toBe(0);
});

it('only lets admins connect the sending account', function () {
    signInAs(Role::Creator);

    $this->get(route('settings.email.connect'))->assertForbidden();
});

it('sends mail through the Gmail API with a refreshed token', function () {
    config(['mail.default' => 'gmail_api', 'services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    GmailConnection::create(['email' => 'support@biosteel.com', 'refresh_token' => 'refresh-123']);
    Cache::forget(GmailApiTransport::TOKEN_CACHE_KEY);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-abc']),
        'gmail.googleapis.com/*' => Http::response(['id' => 'msg-1']),
    ]);

    Mail::raw('Hello', fn ($m) => $m->to('someone@example.com')->subject('Hi'));

    Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['refresh_token'] === 'refresh-123');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'messages/send') && $r->hasHeader('Authorization', 'Bearer access-abc') && filled($r['raw']));
});

it('fails loudly when no sending account is connected', function () {
    config(['mail.default' => 'gmail_api']);
    Cache::forget(GmailApiTransport::TOKEN_CACHE_KEY);

    Mail::raw('Hello', fn ($m) => $m->to('someone@example.com'));
})->throws(RuntimeException::class, 'No Gmail account is connected');

it('sends a test email to the admin', function () {
    Notification::fake();
    $admin = signInAs(Role::Admin);
    GmailConnection::create(['email' => 'support@biosteel.com', 'refresh_token' => 'x']);

    Livewire::test(EmailConnection::class)->assertSee('support@biosteel.com')->call('sendTest')->assertDispatched('saved');

    Notification::assertSentTo($admin, TestEmailNotification::class);
});
