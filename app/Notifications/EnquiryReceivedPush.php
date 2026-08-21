<?php

namespace App\Notifications;

use App\Models\Enquiry;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new lead from the website's enquiry form.
 *
 * A separate class from EnquiryReceived on purpose -- that one is dispatched
 * to an AnonymousNotifiable via Notification::route('mail', ...), which has
 * no push tokens to route to, so adding 'fcm' to its via() would do nothing.
 * This one is Notification::send() to real User models instead.
 */
class EnquiryReceivedPush extends Notification
{
    use Queueable;

    public function __construct(public Enquiry $enquiry) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: 'New enquiry from '.$this->enquiry->name,
            body: Str::limit((string) $this->enquiry->message, 150),
            url: route('enquiries.show', $this->enquiry),
            tag: 'enquiry-'.$this->enquiry->id,
        );
    }
}
