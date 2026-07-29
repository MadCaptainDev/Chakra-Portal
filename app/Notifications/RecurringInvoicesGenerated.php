<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RecurringInvoicesGenerated extends Notification
{
    use Queueable;

    public function __construct(
        public int $count,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plural = $this->count === 1 ? 'invoice' : 'invoices';

        return (new MailMessage)
            ->subject("{$this->count} recurring {$plural} ready for approval")
            ->line("{$this->count} recurring {$plural} " . ($this->count === 1 ? 'has' : 'have') . ' been generated and ' . ($this->count === 1 ? 'is' : 'are') . ' waiting for your approval before being sent.')
            ->action('Review Pending Invoices', route('invoices.index', ['status' => 'pending_approval']))
            ->line('Nothing has been sent to any client yet - review and approve each one first.');
    }
}
