<?php

namespace App\Notifications;

use App\Auth\CompanyDomains;
use App\Models\Invitation;
use App\Settings\Settings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(
        public Invitation $invitation,
        public string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviter = $this->invitation->inviter?->name ?? 'A teammate';
        $appName = config('app.name');
        $usesGoogle = app(CompanyDomains::class)->includes($this->invitation->email);

        return (new MailMessage)
            ->subject("You're invited to {$appName}")
            ->greeting('Hello,')
            ->line("{$inviter} has invited you to {$appName} as {$this->invitation->role->label()}"
                .($this->invitation->brand ? " in {$this->invitation->brand->name}." : '.'))
            ->line($usesGoogle
                ? "Open the link and sign in with your Google account ({$this->invitation->email})."
                : 'Open the link to choose a password for your account.')
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line("The link works until {$this->invitation->expires_at->timezone(app(Settings::class)->get('platform.timezone'))->format('F j, Y g:i A T')}.");
    }
}
