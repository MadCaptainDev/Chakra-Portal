<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The morning nudge to actually open the dashboard -- deliberately terse
 * (a push notification's body is not where a multi-section digest belongs),
 * with the real detail (which accounts are behind target and by how much,
 * a suggested shoot-by date for each) staying on dashboard.blade.php's own
 * "Behind target this month" card, which already existed and now carries
 * one more column. See SendDailyDigest for what decided these three counts.
 */
class DailyDigestReady extends Notification
{
    use Queueable;

    /**
     * @param  array{overdueCount: int, overdueAmount: float, shootsThisWeek: int, behindCount: int}  $summary
     */
    public function __construct(public array $summary) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $parts = [];

        if ($this->summary['overdueCount'] > 0) {
            $parts[] = $this->summary['overdueCount'].' overdue '.($this->summary['overdueCount'] === 1 ? 'invoice' : 'invoices');
        }

        if ($this->summary['shootsThisWeek'] > 0) {
            $parts[] = $this->summary['shootsThisWeek'].' '.($this->summary['shootsThisWeek'] === 1 ? 'shoot' : 'shoots').' this week';
        }

        if ($this->summary['behindCount'] > 0) {
            $parts[] = $this->summary['behindCount'].' behind target';
        }

        return new PushMessage(
            title: 'Studio digest',
            body: $parts === [] ? 'Nothing urgent — everything on track.' : implode(' · ', $parts),
            url: route('dashboard'),
            tag: 'daily-digest-'.now()->toDateString(),
        );
    }
}
