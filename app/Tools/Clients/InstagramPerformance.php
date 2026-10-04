<?php

namespace App\Tools\Clients;

use App\Models\SocialAccount;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Services\Instagram\InstagramReportData;
use App\Services\MonthlyReportData;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;

class InstagramPerformance extends Tool
{
    public function name(): string
    {
        return 'instagram_performance';
    }

    public function title(): string
    {
        return 'Instagram performance for a client';
    }

    public function group(): string
    {
        return 'Clients';
    }

    public function description(): string
    {
        return 'Organic Instagram results for a client\'s connected account over a month: reach, views, '
            .'engagement (and what it is made of), followers and net new followers, posts by format, and '
            .'the top posts by reach. Figures come from the portal\'s last sync with Instagram -- '
            .'last_synced_at says how fresh they are. Paid ads are NOT here (see list_ad_reports). '
            .'Only report numbers this returns; a null means Instagram gave none, not zero. Read-only.';
    }

    public function permission(): ?string
    {
        return 'clients.view';
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Client id or portal name.'],
            'month' => ['type' => 'string', 'description' => 'Optional. YYYY-MM; defaults to the current month so far.'],
            'top_posts' => ['type' => 'integer', 'description' => 'Optional. How many top posts, 1-10; default 5.'],
        ], ['client']);
    }

    public function handle(array $arguments, User $user): array
    {
        $client = ClientResolver::resolve($arguments['client'] ?? null);

        $month = (string) ($arguments['month'] ?? now()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new ToolException('month must be YYYY-MM.');
        }

        $account = $client->socialAccounts()->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)->first();
        if (! $account) {
            throw new ToolException("{$client->name} has no Instagram account connected to the portal.");
        }

        [$since, $until] = MonthlyReportData::monthRange(Carbon::createFromFormat('Y-m-d', $month.'-01'));
        $top = max(1, min(10, (int) ($arguments['top_posts'] ?? 5)));

        $content = InstagramReportData::contentPerformance($account, $since, $until, 200);

        return [
            'client' => $client->name,
            'account' => $account->handle(),
            'period' => $since->toDateString().' to '.$until->toDateString(),
            'last_synced_at' => $account->last_synced_at?->format('Y-m-d H:i'),
            'overview' => InstagramReportData::overview($account, $since, $until),
            'engagement_breakdown' => InstagramReportData::engagementBreakdown($account, $since, $until),
            'posts_published' => $content->count(),
            'posts_by_format' => InstagramReportData::formatBreakdown($content),
            'top_posts_by_reach' => $content->take($top)->map(fn (SocialMediaItem $item) => [
                'posted_at' => $item->posted_at?->format('Y-m-d'),
                'type' => $item->typeLabel(),
                'caption' => $item->shortCaption(80),
                'reach' => $item->metricValue('reach'),
                'views' => $item->metricValue('views'),
                'engagement' => $item->metricValue('total_interactions'),
                'link' => $item->permalink,
            ])->values()->all(),
        ];
    }
}
