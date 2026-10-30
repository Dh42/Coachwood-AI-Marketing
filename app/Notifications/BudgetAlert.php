<?php

namespace App\Notifications;

use App\Budgets\Budgets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The company's monthly budget has passed its warning line. Sent to the
 * platform admins once a month, under the bell and by email.
 */
class BudgetAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public float $spent, public float $limit, public int $percent, public string $month) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function title(): string
    {
        return "The company budget for {$this->month} is {$this->percent}% used";
    }

    public function body(): string
    {
        return sprintf('$%s of the $%s monthly budget has been spent or reserved. Once it\'s used up, no new run can start until next month or until the budget is raised under Settings → Budgets and spend.', Budgets::money($this->spent), Budgets::money($this->limit));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return ['event' => 'budget', 'title' => $this->title(), 'body' => $this->body(), 'url' => route('spend'), 'brand' => ''];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting($this->title())
            ->line($this->body())
            ->action('Open Spend', route('spend'))
            ->line('You get this because you\'re a platform admin.');
    }
}
