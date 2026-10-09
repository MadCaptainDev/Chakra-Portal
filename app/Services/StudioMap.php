<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\Invoice;
use App\Models\Proposal;
use App\Models\Script;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\TimesheetEntry;
use App\Models\Todo;
use App\Models\User;
use App\Services\Insights\EffortVersusRevenue;
use App\Services\Insights\NeedsAttention;
use App\Support\ContentForecast;
use App\Support\TimesheetVenture;
use Illuminate\Support\Carbon;
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
        $perHourByClient = $hours['rows']->pluck('per_hour', 'client_id');
        $invoicedByClient = $hours['rows']->pluck('invoiced', 'client_id');

        // The Content lens: days of unposted reels left. Regular clients with
        // a content account only -- the rest have no runway to measure.
        $forecast = ContentForecast::forAllClients()->keyBy(fn (array $row) => $row['client']->id);

        // What the map's actions need: the shoots crew can be dropped onto
        // (today and the next fortnight, not yet wrapped), the proposals a
        // client could be re-sent, and whose brief is still outstanding.
        $upcoming = Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereNull('finished_at')
            ->whereBetween('starts_at', [$today->copy()->startOfDay(), $today->copy()->addDays(14)->endOfDay()])
            ->with('crew.user')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('client_id');

        $openProposals = Proposal::query()
            ->whereIn('status', [Proposal::STATUS_SENT, Proposal::STATUS_VIEWED])
            ->whereNotNull('client_id')
            ->latest('updated_at')
            ->get(['id', 'client_id', 'title', 'status'])
            ->groupBy('client_id');

        $briefsPending = ClientBrief::query()
            ->where('status', '!=', ClientBrief::STATUS_SUBMITTED)
            ->pluck('client_id')
            ->flip();

        $clients = Client::query()
            ->where(fn ($q) => $q->where('is_active', true)
                ->orWhereIn('id', $unpaid->keys()->filter())
                ->orWhereIn('id', $onMap->keys()->filter()))
            ->orderBy('name')
            ->get();

        $buildings = $clients->map(function (Client $client) use (
            $unpaid, $onMap, $rolling, $nextShoot, $scriptsWaiting, $proposalsWaiting, $hoursByClient,
            $perHourByClient, $invoicedByClient, $forecast, $upcoming, $openProposals, $briefsPending
        ) {
            $runway = $forecast->get($client->id);
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
                'invoiced_month' => round((float) ($invoicedByClient[$client->id] ?? 0)),
                // Null, not zero, with no hours logged -- see EffortVersusRevenue.
                'per_hour' => $perHourByClient[$client->id] ?? null,
                'content' => $runway ? [
                    'status' => $runway['status'],
                    'remaining' => $runway['remaining'],
                    'days_left' => $runway['depletion_date'] ? (int) today()->diffInDays($runway['depletion_date'], false) : null,
                ] : null,
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

                // ——— For the map's actions ———
                'phone' => $client->phone,
                'venture' => TimesheetVenture::canonicalForClient($client),
                'upcoming_shoots' => $upcoming->get($client->id, collect())->map(fn (Shoot $s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'when' => $s->starts_at->isToday() ? 'Today, '.$s->starts_at->format('g:i A') : $s->starts_at->format('D j M, g:i A'),
                    'crew' => $s->crew->filter(fn (ShootCrew $c) => $c->user)->map(fn (ShootCrew $c) => [
                        'id' => $c->user_id, 'name' => $c->user->name, 'role' => $c->role,
                    ])->values()->all(),
                ])->values()->all(),
                'unpaid_invoices' => $invoices
                    ->sortByDesc(fn (Invoice $i) => $i->isOverdue() ? $i->due_date->diffInDays(today()) : -1)
                    ->map(fn (Invoice $i) => [
                        'id' => $i->id,
                        'number' => $i->invoice_number,
                        'balance' => round($i->balanceDue()),
                        'overdue_days' => $i->isOverdue() ? (int) $i->due_date->diffInDays(today()) : 0,
                        'sendable' => $i->isSendableViaWhatsapp(),
                    ])->values()->all(),
                'open_proposals' => $openProposals->get($client->id, collect())
                    ->map(fn (Proposal $p) => ['id' => $p->id, 'title' => $p->title, 'status' => $p->status])
                    ->values()->all(),
                'brief_pending' => filled($client->phone) && $briefsPending->has($client->id),
            ];
        })->values();

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
                'per_hour' => $hours['totals']['per_hour'],
            ],
            'buildings' => $buildings->all(),
            'day' => self::day(),
            'team' => self::team(),
            'attention' => NeedsAttention::all()->values()->all(),
        ];
    }

    /**
     * Today, as a timeline the page can play: every shoot with anything
     * happening today, with its crew and the moments it reached, rolled and
     * wrapped, and every event in order -- what the live feed scrolls and
     * the replay slider scrubs through. Times are epoch milliseconds, the
     * one format JavaScript's Date and a range input both take as-is.
     *
     * @return array<string, mixed>
     */
    private static function day(): array
    {
        $start = today();
        $end = today()->endOfDay();

        $shoots = Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->where(fn ($q) => $q
                ->whereBetween('starts_at', [$start, $end])
                ->orWhereBetween('reached_at', [$start, $end])
                ->orWhereBetween('finished_at', [$start, $end])
                ->orWhere(fn ($q) => $q->inProgress()))
            ->with(['client', 'crew.user', 'reachedBy', 'startedBy', 'videos.recordedBy'])
            ->orderBy('starts_at')
            ->get();

        $ms = fn (?Carbon $at) => $at?->getTimestampMs();
        $events = collect();

        foreach ($shoots as $shoot) {
            $base = ['shoot_id' => $shoot->id, 'client_id' => $shoot->client_id, 'shoot' => $shoot->title, 'client' => $shoot->client?->name];

            if ($shoot->reached_at?->between($start, $end)) {
                $events->push($base + ['type' => 'reached', 'at' => $ms($shoot->reached_at), 'who' => $shoot->reachedBy?->name]);
            }
            if ($shoot->started_at?->between($start, $end)) {
                $events->push($base + ['type' => 'rolling', 'at' => $ms($shoot->started_at), 'who' => $shoot->startedBy?->name]);
            }
            foreach ($shoot->videos as $video) {
                if ($video->created_at->between($start, $end)) {
                    $events->push($base + [
                        'type' => 'video',
                        'at' => $ms($video->created_at),
                        'who' => $video->recordedBy?->name,
                        'id' => 'v'.$video->id,
                        'title' => $video->name,
                        'photo' => $video->photoUrl(),
                    ]);
                }
            }
            if ($shoot->finished_at?->between($start, $end)) {
                $events->push($base + ['type' => 'wrapped', 'at' => $ms($shoot->finished_at), 'who' => null]);
            }
        }

        $events = $events->sortBy('at')->values()
            ->map(fn (array $e) => $e + ['id' => $e['type'].'-'.$e['shoot_id']])
            ->map(fn (array $e) => $e + ['time' => Carbon::createFromTimestampMs($e['at'])->timezone(config('app.timezone'))->format('g:i A')]);

        // The slider starts at 6 AM, or earlier if the day did.
        $from = min($start->copy()->setTime(6, 0)->getTimestampMs(), $events->min('at') ?? PHP_INT_MAX);

        return [
            'from' => $from,
            'now' => now()->getTimestampMs(),
            'shoots' => $shoots->map(fn (Shoot $s) => [
                'id' => $s->id,
                'client_id' => $s->client_id,
                'title' => $s->title,
                'crew' => $s->crew->map(fn (ShootCrew $c) => $c->user?->name)->filter()->values()->all(),
                'crew_ids' => $s->crew->pluck('user_id')->values()->all(),
                'starts_at' => $ms($s->starts_at),
                'reached_at' => $ms($s->reached_at),
                'started_at' => $ms($s->started_at),
                'finished_at' => $ms($s->finished_at),
            ])->values()->all(),
            'events' => $events->all(),
        ];
    }

    /**
     * Everybody on staff, for the Crew lens: who is out on a shoot (the page
     * works out where from day.shoots) and who is back at the tower, with
     * hours logged today so an idle-looking figure who has logged seven of
     * them reads as busy rather than absent.
     *
     * Their open to-dos ride along for the person panel -- the count, and the
     * five due soonest.
     *
     * @return list<array<string, mixed>>
     */
    private static function team(): array
    {
        $minutes = TimesheetEntry::query()
            ->counted()
            ->whereDate('worked_on', today())
            ->selectRaw('user_id, SUM(minutes) as minutes')
            ->groupBy('user_id')
            ->pluck('minutes', 'user_id');

        $todos = Todo::query()->open()->orderBy('due_on')->get(['id', 'user_id', 'title', 'due_on'])->groupBy('user_id');

        return User::query()->staff()->orderBy('name')->get(['id', 'name', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'hours_today' => round((float) ($minutes[$u->id] ?? 0) / 60, 1),
                'open_todos' => $todos->get($u->id, collect())->count(),
                'todos' => $todos->get($u->id, collect())->take(5)->map(fn (Todo $t) => [
                    'title' => $t->title,
                    'due' => $t->due_on?->isToday() ? 'Today' : $t->due_on?->format('j M'),
                    'overdue' => $t->due_on?->isPast() && ! $t->due_on->isToday(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
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
            'reached' => $shoot->reached_at?->format('g:i A'),
            'crew' => $shoot->crew->map(fn (ShootCrew $c) => $c->user?->name)->filter()->values()->all(),
            'url' => route('shoots.show', $shoot),
        ];
    }
}
