<?php

namespace App\Notifications;

use App\Settings\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Something happened to a request that its owner (or whoever asked for a
 * change) should know about. Always a notice under the bell; also an email
 * when the person has chosen that event under Your account, unless they
 * saw the notice in the app while the email waited.
 */
class RequestNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** The planner has questions, a plan is ready to confirm, or planning failed. */
    public const NEEDS_YOU = 'needs_you';

    /** A run finished or failed. */
    public const DONE = 'done';

    /** A change or a shape run again finished or failed. */
    public const CHANGES = 'changes';

    /** The events a person can choose to be emailed about, in the order they're offered. */
    public const EVENTS = [
        self::NEEDS_YOU => 'A request needs me: the planner has a question, or a plan is ready to confirm',
        self::DONE => 'A run has finished, or failed',
        self::CHANGES => 'A change I asked for, or a shape run again, has finished',
    ];

    public function __construct(
        public string $event,
        public string $title,
        public string $body,
        public string $url,
        public int $requestId,
        public int $brandId,
        public string $brand,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $emailed = (array) app(Settings::class)->get('notify.email', null, $notifiable->id);

        return in_array($this->event, $emailed, true) ? ['database', 'mail'] : ['database'];
    }

    /**
     * The email waits a little, so someone watching the page isn't emailed
     * about what they've just seen.
     */
    public function withDelay(object $notifiable, string $channel): ?Carbon
    {
        return $channel === 'mail' ? now()->addMinutes((int) app(Settings::class)->get('notify.email_delay')) : null;
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel !== 'mail' || ! DatabaseNotification::whereKey($this->id)->whereNotNull('read_at')->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return ['event' => $this->event, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url, 'request_id' => $this->requestId, 'brand_id' => $this->brandId, 'brand' => $this->brand];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->brand}: {$this->title}")
            ->greeting($this->title)
            ->line($this->body)
            ->action('Open the request', $this->url)
            ->line('You get this because of what you chose under Your account → Notices.');
    }
}
