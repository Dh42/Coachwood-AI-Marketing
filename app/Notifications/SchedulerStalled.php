<?php

namespace App\Notifications;

use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The scheduler has stopped: nothing has run for longer than the setting.
 * Sent to platform admins, once per stall.
 */
class SchedulerStalled extends Notification
{
    public function __construct(public ?CarbonImmutable $lastRunAt) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tz = app(Settings::class)->get('platform.timezone');
        $since = $this->lastRunAt ? $this->lastRunAt->timezone($tz)->format('M j, g:i A') : 'it was set up';

        return (new MailMessage)
            ->subject('The scheduled tasks have stopped')
            ->greeting('The scheduled tasks have stopped')
            ->line("Nothing has run since {$since}. Until it's going again, nothing that runs on a timer happens: no syncs, no clean-ups and no summaries.")
            ->line('This usually means the scheduler\'s entry on the server (the cron job that runs every minute) has stopped. The Scheduled tasks page shows what to check.')
            ->action('Open Scheduled tasks', route('scheduled-tasks'))
            ->line('You get this because you\'re a platform admin.');
    }
}
