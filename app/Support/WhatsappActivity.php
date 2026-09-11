<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\Invoice;
use App\Models\MonthlyReportNote;
use App\Models\Quotation;
use App\Models\Shoot;
use App\Models\WhatsappCampaignLog;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What actually went out over WhatsApp, and when -- day by day, in plain
 * language, instead of the raw "Recent events" webhook log (Settings ->
 * WhatsApp) that mixes inbound, outbound, delivery status and webhook
 * errors into one undifferentiated list. That log still exists and is
 * still the right place to verify a webhook is wired up; this is the
 * place to answer "what did we actually send today".
 *
 * Every outgoing message -- a campaign, an automated staff alert, a
 * document-ready notice, a manual reply -- already lands in
 * WhatsappWebhookEvent as one TYPE_OUTGOING row (see WhatsappSender::
 * record()), so nothing new is tracked here. This only reads and
 * categorises what is already being written.
 */
class WhatsappActivity
{
    /**
     * Every template name this app knows the purpose of, mapped to a label
     * a person reads instead of a Meta template slug. Anything not in this
     * list (a campaign's own template, or free text) is labelled at read
     * time instead -- see categorise().
     *
     * @var array<string, string>
     */
    private const KNOWN_TEMPLATES = [
        Invoice::WHATSAPP_TEMPLATE => 'Invoice ready',
        Quotation::WHATSAPP_TEMPLATE => 'Quotation ready',
        MonthlyReportNote::WHATSAPP_TEMPLATE => 'Monthly report ready',
        ClientBrief::WHATSAPP_TEMPLATE => 'Brand brief nudge',
        Shoot::WHATSAPP_TEMPLATE_REMINDER => 'Shoot reminder',
        Shoot::WHATSAPP_TEMPLATE_MISSING_CONTENT => 'Missing content alert',
        Client::WHATSAPP_TEMPLATE_DEPLETION => 'Content depletion warning',
    ];

    /**
     * The automations that send on their own, unattended -- what "daily
     * schedule" actually means here. Read straight from routes/console.php;
     * update this list when that file changes, there is no way to read a
     * live Schedule definition back out of the framework at request time.
     *
     * @return list<array{time: string, label: string}>
     */
    public static function dailySchedule(): array
    {
        return [
            ['time' => '09:00', 'label' => 'Content depletion warnings — staff'],
            ['time' => '09:00', 'label' => 'Missing content alerts — staff'],
            ['time' => '18:00', 'label' => "Tomorrow's shoot reminders — crew"],
            ['time' => '09:00 on the 2nd of each month', 'label' => 'Monthly report ready — clients'],
            ['time' => 'every minute (dispatch only, no fixed send time)', 'label' => 'Scheduled/queued campaigns'],
        ];
    }

    /**
     * One day's outgoing messages, newest first, each carrying its category
     * label and (for a campaign send) which campaign it belongs to.
     *
     * @return Collection<int, array{event: WhatsappWebhookEvent, category: string, campaign: string|null}>
     */
    public static function forDay(Carbon $day): Collection
    {
        $events = WhatsappWebhookEvent::query()
            ->where('type', WhatsappWebhookEvent::TYPE_OUTGOING)
            ->whereBetween('occurred_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderByDesc('occurred_at')
            ->get();

        return self::annotate($events);
    }

    /**
     * Per-day totals for the last $days days (today first), each broken
     * down by category -- the actual "daily schedule" view: a glance at
     * whether today looks like every other day or something ran twice.
     *
     * @return Collection<int, array{date: Carbon, total: int, by_category: Collection<string, int>}>
     */
    public static function dailyTotals(int $days = 14): Collection
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $events = self::annotate(
            WhatsappWebhookEvent::query()
                ->where('type', WhatsappWebhookEvent::TYPE_OUTGOING)
                ->where('occurred_at', '>=', $since)
                ->get()
        );

        $byDay = $events->groupBy(fn (array $row) => $row['event']->occurred_at->toDateString());

        return collect(range(0, $days - 1))
            ->map(function (int $daysAgo) use ($byDay) {
                $date = now()->subDays($daysAgo)->startOfDay();
                $rows = $byDay->get($date->toDateString(), collect());

                return [
                    'date' => $date,
                    'total' => $rows->count(),
                    'by_category' => $rows->countBy('category'),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, WhatsappWebhookEvent>  $events
     * @return Collection<int, array{event: WhatsappWebhookEvent, category: string, campaign: string|null}>
     */
    private static function annotate(Collection $events): Collection
    {
        if ($events->isEmpty()) {
            return collect();
        }

        // One query for every campaign log these events might belong to,
        // keyed by wamid -- an N+1 here would mean a query per row on a
        // list that's meant to be skimmed, not waited on.
        $wamids = $events->pluck('external_id')->filter()->values();

        $campaignNames = $wamids->isEmpty() ? collect() : WhatsappCampaignLog::query()
            ->whereIn('wamid', $wamids)
            ->with('campaign:id,name')
            ->get()
            ->pluck('campaign.name', 'wamid');

        return $events->map(function (WhatsappWebhookEvent $event) use ($campaignNames) {
            $campaign = $campaignNames->get($event->external_id);

            return [
                'event' => $event,
                'category' => $campaign ? 'Campaign' : self::categorise($event),
                'campaign' => $campaign,
            ];
        });
    }

    private static function categorise(WhatsappWebhookEvent $event): string
    {
        $template = data_get($event->payload, 'template.name');

        if ($template && array_key_exists($template, self::KNOWN_TEMPLATES)) {
            return self::KNOWN_TEMPLATES[$template];
        }

        if ($template) {
            return 'Other template';
        }

        return match ($event->message_type) {
            'text' => 'Reply',
            'interactive' => 'Interactive message',
            default => 'Other',
        };
    }
}
