<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Support\WhatsappServiceWindow;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Tell a client one of their videos is getting good views, through the
 * studio's connected WhatsApp number so the send lands in WhatsApp CRM.
 */
class ReelPerformanceNudge
{
    public const WHATSAPP_TEMPLATE = 'reel_performing_well_v1';

    /** Never call a video "good" below this, however weak the account's other videos are. */
    public const MIN_VIEWS = 1000;

    /** Good means this many times the account's median views for the month. */
    public const MEDIAN_MULTIPLE = 1.5;

    /**
     * The views a video needs to count as doing well, or null when nothing
     * this month has synced views to compare against.
     *
     * @param  Collection<int, ContentItem>  $items
     */
    public static function threshold(Collection $items): ?int
    {
        $views = $items
            ->map(fn (ContentItem $item) => $item->socialMediaItem?->metricValue('views'))
            ->filter(fn ($v) => $v !== null && $v > 0)
            ->sort()
            ->values();

        if ($views->isEmpty()) {
            return null;
        }

        $median = $views->count() % 2
            ? $views[intdiv($views->count(), 2)]
            : ($views[$views->count() / 2 - 1] + $views[$views->count() / 2]) / 2;

        return (int) max(self::MIN_VIEWS, ceil($median * self::MEDIAN_MULTIPLE));
    }

    public function send(Client $client, ContentItem $item, User $by): WhatsappConversation
    {
        if (! filled($client->phone)) {
            throw new RuntimeException('This client has no phone number on file.');
        }

        $views = $item->socialMediaItem?->metricValue('views');

        if ($views === null) {
            throw new RuntimeException('This video has no synced views yet.');
        }

        $title = $item->title ?: 'your latest video';
        $formattedViews = number_format($views);
        $sender = WhatsappSender::make();

        $message = $this->messageText($client, $title, $formattedViews);

        if (WhatsappServiceWindow::isOpen($client->phone)) {
            $sender->sendText($client->phone, $message);
        } else {
            $sender->sendTemplate(
                to: $client->phone,
                template: self::WHATSAPP_TEMPLATE,
                bodyParameters: [$client->name, $title, $formattedViews],
            );
        }

        return WhatsappConversation::firstOrCreate(
            ['wa_id' => WhatsappSender::normalise($client->phone)],
            ['last_message_at' => now(), 'last_message_summary' => $message],
        );
    }

    public function messageText(Client $client, string $title, string $views): string
    {
        return 'Hi '.$client->name.' — good news: "'.$title.'" is getting great traction, '
            .$views.' views so far. Take a look and share it with your audience!';
    }
}
