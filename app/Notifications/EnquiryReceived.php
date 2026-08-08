<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnquiryReceived extends Notification
{
    use Queueable;

    /**
     * @param  array<string, string|null>  $enquiry
     */
    public function __construct(
        public array $enquiry,
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
        $name = $this->enquiry['name'];

        $mail = (new MailMessage)
            ->subject("New project enquiry from {$name}")
            // Reply goes straight back to the enquirer, not to ourselves.
            ->replyTo($this->enquiry['email'], $name)
            ->greeting('New enquiry from the website')
            ->line("**Name:** {$name}")
            ->line("**Email:** {$this->enquiry['email']}");

        if (! empty($this->enquiry['phone'])) {
            $mail->line("**Phone:** {$this->enquiry['phone']}");
        }

        if (! empty($this->enquiry['project'])) {
            $mail->line("**Looking for:** {$this->enquiry['project']}");
        }

        return $mail
            ->line('---')
            ->line($this->enquiry['message']);
    }
}
