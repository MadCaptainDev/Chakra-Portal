<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Services\Insights\EffortVersusRevenue;
use App\Services\Insights\NeedsAttention;
use Illuminate\Support\Collection;

/**
 * Everything the Studio Map draws, as one plain array -- the page embeds it,
 * and studio-map/data hands the same thing back for the minute-by-minute
 * refresh, so there is one answer to "what is on the map".
 *
 * Read-only by construction: nothing here writes, and every figure comes
 * from a query the portal already trusts elsewhere -- hours through
 * EffortVersusRevenue (so the TimesheetVenture join, not name matching),
 * problems through NeedsAttention, balances through Invoice::balanceDue().
 *
 * A building is a client worth looking at: every active client, plus any
 * inactive one that still owes money or has a shoot today -- a paused
 * client who owes ₹40,000 is exactly what the map is for.
 */
final class StudioMap
{
    /**
     * One state per building, worst first: the colour it is drawn in is
     * whichever of these applies first.
     */
    public const STATES = ['overdue', 'rolling', 'shoot', 'waiting', 'owes', 'calm'];

    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        $today = today();

        $unpaid = Invoice::unpaid()->with('payments')->get()->groupBy('client_id');

        $shootsToday = Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereDate('starts_at', $today)
            ->with('crew.user')
            ->orderBy('starts_at')
            ->get();

        // Started on a phone and not wrapped -- may have begun yesterday.
        $rolling = Shoot::inProgress()->with('crew.user')->get();
        $onMap = $shootsToday->merge($rolling)->unique('id')->groupBy('client_id');

        $nextShoot = Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->where('starts_at', '>', $today->copy()->endOfDay())
            ->orderBy('starts_at')
            ->get(['id', 'client_id', 'title', 'starts_at'])
            ->unique('client_id')
            ->keyBy('client_id');

        $scriptsWaiting = Script::query()
            ->where('status', Script::STATUS_CLIENT_REVIEW)
            ->selectRaw('client_id, COUNT(*) as n')
            ->groupBy('client_id')
            ->pluck('n', 'client_id');

        $proposalsWaiting = Proposal::query()
            ->whereIn('status', [Proposal::STATUS_SENT, Proposal::STATUS_VIEWED])
            ->whereNotNull('client_id')
            ->selectRaw('client_id, COUNT(*) as n')
            ->groupBy('client_id')
            ->pluck('n', 'client_id');

        $hours = EffortVersusRevenue::between($today->copy()->startOfMonth(), $today->copy()->endOfMonth());
        $hoursByClient = $hours['rows']->pluck('hours', 'client_id');

        $clients = Client::query()
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', $unpaid->keys()->filter())
                ->orWhereIn('id', $onMap->keys()->filter()))
            ->orderBy('name')
            ->get();

        $buildings = $clients->map(function (Client $client) use (
            $unpaid, $onMap, $rolling, $nextShoot, $scriptsWaiting, $proposalsWaiting, $hoursByClient
        ) {
            $invoices = $unpaid->get($client->id, collect());
            $owed = (float) $invoices->sum(fn (Invoice $i) => $i->balanceDue());
            $overdueDays = (int) $invoices
                ->filter(fn (Invoice $i) => $i->isOverdue())
                ->map(fn (Invoice $i) => $i->due_date->diffInDays(today()))
                ->max();

            $shoots = $onMap->get($client->id, collect());
            $isRolling = $shoots->contains(fn (Shoot $s) => $rolling->contains('id', $s->id));
            $next = $nextShoot->get($client->id);
            $scripts = (int) ($scriptsWaiting[$client->id] ?? 0);
            $proposals = (int) ($proposalsWaiting[$client->id] ?? 0);

            $state = match (true) {
                $overdueDays > 0 => 'overdue',
                $isRolling => 'rolling',
                $shoots->isNotEmpty() => 'shoot',
                $scripts + $proposals > 0 => 'waiting',
                $owed > 0 => 'owes',
                default => 'calm',
            };

            return [
                'id' => $client->id,
                'name' => $client->name,
                'type' => $client->client_type,
                'active' => (bool) $client->is_active,
                'state' => $state,
                'hours' => (float) ($hoursByClient[$client->id] ?? 0),
                'owed' => round($owed),
                'overdue_days' => $overdueDays,
                'scripts_waiting' => $scripts,
                'proposals_waiting' => $proposals,
                'shoots_today' => $shoots->map(fn (Shoot $s) => self::shoot($s, $rolling))->values()->all(),
                'next_shoot' => $next ? [
                    'title' => $next->title,
                    'when' => $next->starts_at->format('D j M, g:i A'),
                    'url' => route('shoots.show', $next->id),
                ] : null,
                'links' => [
                    'client' => route('clients.show', $client),
                    'invoices' => route('invoices.index', ['client' => $client->id]),
                ],
            ];
        })->values();

        $allShoots = $shootsToday->merge($rolling)->unique('id');

        return [
            'generated_at' => now()->format('g:i A'),
            'totals' => [
                'owed' => round($buildings->sum('owed')),
                'overdue' => round((float) $unpaid->flatten()
                    ->filter(fn (Invoice $i) => $i->isOverdue())
                    ->sum(fn (Invoice $i) => $i->balanceDue())),
                'shoots_today' => $shootsToday->count(),
                'rolling' => $rolling->count(),
                'hours_month' => $hours['totals']['hours'],
                'waiting_on_clients' => $scriptsWaiting->sum() + $proposalsWaiting->sum(),
            ],
            'buildings' => $buildings->all(),
            'crew' => self::crew($allShoots, $rolling),
            'attention' => NeedsAttention::all()->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function shoot(Shoot $shoot, Collection $rolling): array
    {
        return [
            'id' => $shoot->id,
            'title' => $shoot->title,
            'when' => $shoot->starts_at?->format('g:i A'),
            'location' => $shoot->location,
            'rolling' => $rolling->contains('id', $shoot->id),
            'crew' => $shoot->crew->map(fn (ShootCrew $c) => $c->user?->name)->filter()->values()->all(),
            'url' => route('shoots.show', $shoot),
        ];
    }

    /**
     * One walker per crew member per shoot on the map. A shoot with nobody
     * on it gets no walker -- NeedsAttention already says so, and an empty
     * street says it again without inventing a person.
     *
     * @return list<array{name: string, client_id: ?int, rolling: bool}>
     */
    private static function crew(Collection $shoots, Collection $rolling): array
    {
        return $shoots->flatMap(fn (Shoot $shoot) => $shoot->crew
            ->map(fn (ShootCrew $c) => $c->user?->name)
            ->filter()
            ->map(fn (string $name) => [
                'name' => $name,
                'client_id' => $shoot->client_id,
                'rolling' => $rolling->contains('id', $shoot->id),
            ]))
            ->values()
            ->all();
    }
}
