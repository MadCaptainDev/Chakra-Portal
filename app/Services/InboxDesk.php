<?php

namespace App\Services;

use App\Models\Routine;
use App\Models\RoutineCheckpoint;
use App\Models\RoutineField;
use App\Models\RoutineOccurrence;
use App\Models\SocialAccount;
use App\Models\SocialWebhookEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Inbox Check: every account-scoped routine laid out as a grid of
 * accounts × checkpoints (DMs, Comments) for today, ticked one tile at a time.
 *
 * Nothing here is a new kind of task. The routine engine already schedules,
 * fans out per account and records who did what -- this is the screen, the
 * widget feed and the tap-to-check over it, so the checking board, My
 * Routines and this all read and write the same rows.
 *
 * Access mirrors My Routines exactly: admins see every active routine, anyone
 * else only routines they are permitted on, and only rows that are shared or
 * assigned to them. board(), check() and undo() all start from visible(), so
 * what can be seen and what can be acted on cannot drift apart.
 */
class InboxDesk
{
    /** A webhook feed quieter than this is treated as not set up for the account. */
    private const ACTIVITY_FEED_DAYS = 30;

    public function __construct(
        private readonly RoutineCompleter $completer,
        private readonly RoutineOccurrenceGenerator $generator,
    ) {}

    /**
     * Whether this person has anything to check -- drives the sidebar link
     * and whether the widget feed carries an inbox section at all.
     */
    public function hasAccess(User $user): bool
    {
        return ! $user->isClient() && $this->routinesQuery($user)->exists();
    }

    /**
     * Today's board, as plain arrays -- rendered into the page on load and
     * served again as JSON to every poll, so both are the same shape.
     *
     * @return array<string, mixed>
     */
    public function board(User $user): array
    {
        $this->ensureGenerated();

        $today = today();
        $routines = $this->routinesQuery($user)
            ->with(['checkpoints', 'fields'])
            ->orderBy('title')
            ->get();

        $rows = $routines->isEmpty() ? collect() : $this->visible($user)
            ->whereIn('routine_id', $routines->modelKeys())
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $open) => $open
                    ->where('status', RoutineOccurrence::STATUS_OPEN)
                    ->whereDate('due_on', '<=', $today->toDateString()))
                ->orWhereDate('due_on', $today->toDateString()))
            ->with(['subject', 'completedByUser:id,name'])
            ->get()
            ->loadMorph('subject', [SocialAccount::class => ['client:id,name']]);

        $boards = $routines
            ->map(fn (Routine $routine) => $this->routineBoard($user, $routine, $rows->where('routine_id', $routine->id)))
            ->values();

        $done = $boards->sum('done');
        $total = $boards->sum('total');

        return [
            'date' => $today->toDateString(),
            'date_label' => $today->format('l, j M'),
            'generated_at' => now()->toIso8601String(),
            'generated_label' => now()->format('g:i A'),
            'is_admin' => $user->isAdmin(),
            'routines' => $boards->all(),
            'totals' => [
                'done' => $done,
                'total' => $total,
                'left' => $total - $done,
                'late' => $boards->sum('late'),
                'accounts_left' => $boards->sum('accounts_left'),
            ],
            'feed' => $this->feed($rows),
        ];
    }

    /**
     * Tick one tile. The tile stands for the oldest open row of its cell; a
     * cell days behind closes its whole backlog, the same as My Routines --
     * you checked the inbox, you did not check it once per missed day.
     *
     * The reply count lands on the newest row only. Writing "replied to 6"
     * onto each of three backlog days would report 18 replies that never
     * happened; the older rows close with their defaults instead.
     *
     * @return array{done: int, already: int}
     */
    public function check(User $user, int $occurrenceId, mixed $count = null): array
    {
        $target = $this->visible($user)
            ->where('status', RoutineOccurrence::STATUS_OPEN)
            ->whereDate('due_on', '<=', today()->toDateString())
            ->whereHas('routine', fn (Builder $q) => $q->where('subject_type', Routine::SUBJECT_ACCOUNTS))
            ->find($occurrenceId);

        abort_unless($target, 404);

        $cell = $this->visible($user)
            ->where('status', RoutineOccurrence::STATUS_OPEN)
            ->whereDate('due_on', '<=', today()->toDateString())
            ->where('routine_id', $target->routine_id)
            ->where('checkpoint_id', $target->checkpoint_id)
            ->where('subject_type', $target->subject_type)
            ->where('subject_id', $target->subject_id)
            ->where('assigned_user_id', $target->assigned_user_id)
            ->with('routine.fields')
            ->orderBy('due_on')
            ->get();

        $newest = $cell->last();
        $closed = [];
        $already = 0;

        foreach ($cell as $row) {
            $values = $row->is($newest) ? $this->countValues($row, $count) : [];
            $result = $this->completer->complete($row, $user, $values);
            $result['ok'] ? $closed[] = $row->id : $already++;
        }

        // One tap, one moment: a backlog closed across a second boundary
        // would otherwise read as two ticks, and undo() finds "everything
        // this tap closed" by that shared timestamp.
        if (count($closed) > 1) {
            RoutineOccurrence::query()->whereIn('id', $closed)->update(['completed_at' => now()]);
        }

        return ['done' => count($closed), 'already' => $already];
    }

    /**
     * Take back a tick made today -- your own, or anybody's if you are an
     * admin. Everything closed by that same tap reopens with it, so undoing a
     * backlog close puts the backlog back rather than leaving it half-shut.
     */
    public function undo(User $user, int $occurrenceId): int
    {
        /** @var RoutineOccurrence|null $row */
        $row = $this->visible($user)
            ->where('status', RoutineOccurrence::STATUS_DONE)
            ->find($occurrenceId);

        abort_unless($row && $row->completed_at?->isToday(), 404);
        abort_unless($user->isAdmin() || (int) $row->completed_by === (int) $user->id, 403);

        return DB::transaction(fn () => RoutineOccurrence::query()
            ->where('status', RoutineOccurrence::STATUS_DONE)
            ->where('routine_id', $row->routine_id)
            ->where('checkpoint_id', $row->checkpoint_id)
            ->where('subject_type', $row->subject_type)
            ->where('subject_id', $row->subject_id)
            ->where('assigned_user_id', $row->assigned_user_id)
            ->where('completed_by', $row->completed_by)
            ->where('completed_at', $row->completed_at)
            ->update([
                'status' => RoutineOccurrence::STATUS_OPEN,
                'completed_by' => null,
                'completed_at' => null,
                'values' => null,
                'note' => null,
                'updated_at' => now(),
            ]));
    }

    /**
     * The phone widget's compact version of board().
     *
     * @return array<string, mixed>
     */
    public function widget(User $user): array
    {
        $board = $this->board($user);

        $accounts = collect($board['routines'])
            ->flatMap(fn (array $routine) => collect($routine['accounts'])->map(fn (array $account) => [
                'handle' => $account['handle'],
                'routine' => $routine['title'],
                'left' => $account['left'],
                'late' => $account['late'],
                'checks' => collect($routine['checkpoints'])
                    ->map(fn (array $cp) => [
                        'name' => $cp['name'],
                        'short' => $cp['short'],
                        'state' => $account['cells'][$cp['id']]['state'] ?? 'none',
                        'late' => ($account['cells'][$cp['id']]['late_days'] ?? 0) > 0,
                        'new' => $account['activity'][$cp['id']]['count'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ]))
            // What still needs doing first, then alphabetical: a widget has
            // room for a handful of rows, so they should be the useful ones.
            ->sortBy([
                fn (array $a, array $b) => ($b['left'] > 0) <=> ($a['left'] > 0),
                fn (array $a, array $b) => $b['late'] <=> $a['late'],
                fn (array $a, array $b) => strcmp($a['handle'], $b['handle']),
            ])
            ->values();

        $latest = $board['feed'][0] ?? null;

        return [
            'done' => $board['totals']['done'],
            'total' => $board['totals']['total'],
            'left' => $board['totals']['left'],
            'late' => $board['totals']['late'],
            'accounts_left' => $board['totals']['accounts_left'],
            'accounts' => $accounts->all(),
            'last' => $latest ? [
                'text' => $latest['by'].' · '.$latest['checkpoint'].' · '.$latest['handle'],
                'at' => $latest['at_label'],
            ] : null,
            'url' => route('inbox-desk.index'),
        ];
    }

    /**
     * Generate today's rows if nobody has yet. The widget feed is not behind
     * the routines.catchup middleware, and a phone checked at 8 AM before
     * anyone has opened the portal must not read "0 of 0".
     */
    private function ensureGenerated(): void
    {
        if (Cache::add('inbox-desk-generated-on-'.today()->toDateString(), true, now()->addDay())) {
            $this->generator->run();
        }
    }

    private function routinesQuery(User $user): Builder
    {
        return Routine::query()
            ->active()
            ->where('subject_type', Routine::SUBJECT_ACCOUNTS)
            ->when(! $user->isAdmin(), fn (Builder $q) => $q
                ->whereHas('users', fn (Builder $u) => $u->where('users.id', $user->id)));
    }

    /**
     * Rows this person may see: on a routine they can see, and shared or
     * assigned to them. The same rule as My Routines.
     */
    private function visible(User $user): Builder
    {
        $routineIds = $this->routinesQuery($user)->select('id');

        return RoutineOccurrence::query()
            ->whereIn('routine_id', $routineIds)
            ->where(fn (Builder $q) => $q
                ->whereNull('assigned_user_id')
                ->orWhere('assigned_user_id', $user->id));
    }

    /**
     * @param  Collection<int, RoutineOccurrence>  $rows
     * @return array<string, mixed>
     */
    private function routineBoard(User $user, Routine $routine, Collection $rows): array
    {
        $today = today();

        // No checkpoints is one implicit check per account (null id).
        $checkpoints = $routine->checkpoints->isEmpty()
            ? collect([null])
            : $routine->checkpoints;

        $checkpointRows = $checkpoints->map(fn (?RoutineCheckpoint $cp) => [
            'id' => $cp?->id ?? 0,
            'name' => $cp?->name ?? 'Checked',
            'short' => $this->shortName($cp?->name),
            'kind' => $this->kindOf($cp?->name),
            'field' => $this->countField($routine, $cp?->id),
        ])->values();

        $accounts = $rows
            ->groupBy(fn (RoutineOccurrence $o) => $o->subject_type.':'.$o->subject_id)
            ->map(function (Collection $accountRows) use ($user, $checkpointRows, $today) {
                /** @var RoutineOccurrence $first */
                $first = $accountRows->first();
                $subject = $first->subject;

                $cells = [];
                foreach ($checkpointRows as $cp) {
                    $cells[$cp['id']] = $this->cell(
                        $user,
                        $accountRows->filter(fn (RoutineOccurrence $o) => (int) $o->checkpoint_id === $cp['id']),
                        $cp['field'],
                        $today,
                    );
                }

                $due = collect($cells)->reject(fn (array $c) => $c['state'] === 'none');
                $left = $due->where('state', 'open')->count();

                return [
                    'key' => $first->subject_type.':'.$first->subject_id,
                    'handle' => $first->subjectLabel() ?? 'Account',
                    'name' => $this->accountName($subject),
                    'avatar' => $subject instanceof SocialAccount ? $subject->profile_picture_url : null,
                    'profile_url' => $this->profileUrl($subject),
                    'cells' => $cells,
                    'left' => $left,
                    'late' => $due->filter(fn (array $c) => $c['late_days'] > 0)->count(),
                    'total' => $due->count(),
                    'subject' => $subject,
                ];
            })
            ->sortBy(fn (array $a) => mb_strtolower(ltrim($a['handle'], '@')))
            ->values();

        $activity = $this->activity($accounts, $checkpointRows);

        $accounts = $accounts->map(function (array $account) use ($activity) {
            $account['activity'] = $activity[$account['key']] ?? [];
            unset($account['subject']);

            return $account;
        });

        $total = $accounts->sum('total');
        $left = $accounts->sum('left');

        return [
            'id' => $routine->id,
            'title' => $routine->title,
            'description' => $routine->description,
            'checkpoints' => $checkpointRows->all(),
            'accounts' => $accounts->all(),
            'done' => $total - $left,
            'total' => $total,
            'late' => $accounts->sum('late'),
            'accounts_left' => $accounts->where('left', '>', 0)->count(),
        ];
    }

    /**
     * One tile: open (oldest open row, and how late), settled today, or not
     * due today at all.
     *
     * @param  Collection<int, RoutineOccurrence>  $rows
     * @return array<string, mixed>
     */
    private function cell(User $user, Collection $rows, ?array $field, Carbon $today): array
    {
        $open = $rows->where('status', RoutineOccurrence::STATUS_OPEN)->sortBy('due_on')->values();

        if ($open->isNotEmpty()) {
            /** @var RoutineOccurrence $oldest */
            $oldest = $open->first();

            return [
                'state' => 'open',
                'id' => $oldest->id,
                'late_days' => $oldest->due_on->lt($today) ? (int) $oldest->due_on->diffInDays($today) : 0,
                'outstanding' => $open->count(),
                'by' => null,
                'at' => null,
                'at_label' => null,
                'count' => null,
                'can_undo' => false,
            ];
        }

        /** @var RoutineOccurrence|null $settled */
        $settled = $rows->first(fn (RoutineOccurrence $o) => $o->due_on->isSameDay($today));

        if (! $settled) {
            return ['state' => 'none', 'id' => null, 'late_days' => 0, 'outstanding' => 0, 'by' => null,
                'at' => null, 'at_label' => null, 'count' => null, 'can_undo' => false];
        }

        $isDone = $settled->status === RoutineOccurrence::STATUS_DONE;

        return [
            'state' => $isDone ? 'done' : 'skipped',
            'id' => $settled->id,
            'late_days' => 0,
            'outstanding' => 0,
            'by' => $settled->completedByUser ? (strtok($settled->completedByUser->name, ' ') ?: $settled->completedByUser->name) : null,
            'at' => $settled->completed_at?->toIso8601String(),
            'at_label' => $settled->completed_at?->format('g:i A'),
            'count' => $field && isset($settled->values[$field['key']]) ? (int) $settled->values[$field['key']] : null,
            'note' => $isDone ? null : $settled->note,
            'can_undo' => $isDone
                && $settled->completed_at?->isToday()
                && ($user->isAdmin() || (int) $settled->completed_by === (int) $user->id),
        ];
    }

    /**
     * Today's ticks, newest first -- the "live" strip an owner watches.
     *
     * @param  Collection<int, RoutineOccurrence>  $rows
     * @return list<array<string, mixed>>
     */
    private function feed(Collection $rows): array
    {
        $checkpoints = RoutineCheckpoint::query()
            ->whereIn('id', $rows->pluck('checkpoint_id')->filter()->unique())
            ->pluck('name', 'id');

        return $rows
            ->filter(fn (RoutineOccurrence $o) => $o->status === RoutineOccurrence::STATUS_DONE
                && $o->completed_at?->isToday())
            // A backlog close is several rows from one tap; show it once.
            ->unique(fn (RoutineOccurrence $o) => implode('|', [
                $o->routine_id, $o->checkpoint_id, $o->subject_type, $o->subject_id, $o->completed_at?->timestamp,
            ]))
            ->sortByDesc('completed_at')
            ->take(12)
            ->map(function (RoutineOccurrence $o) use ($checkpoints) {
                $count = collect($o->values ?? [])->first(fn ($v) => is_numeric($v));

                return [
                    'by' => $o->completedByUser ? (strtok($o->completedByUser->name, ' ') ?: $o->completedByUser->name) : 'Someone',
                    'handle' => $o->subjectLabel() ?? 'Account',
                    'checkpoint' => $checkpoints[$o->checkpoint_id] ?? 'Checked',
                    'count' => $count !== null ? (int) $count : null,
                    'at' => $o->completed_at->toIso8601String(),
                    'at_label' => $o->completed_at->format('g:i A'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "4 new chats since the last check", from Instagram's own webhooks.
     *
     * Only for accounts whose feed is actually flowing -- an account with
     * messaging webhooks switched off would otherwise read "0 new", which is
     * an all-clear nobody earned. No feed means no hint at all.
     *
     * @param  Collection<int, array<string, mixed>>  $accounts
     * @param  Collection<int, array<string, mixed>>  $checkpoints
     * @return array<string, array<int, array{count: int, label: string}>>
     */
    private function activity(Collection $accounts, Collection $checkpoints): array
    {
        $social = $accounts->filter(fn (array $a) => $a['subject'] instanceof SocialAccount);

        if ($social->isEmpty() || $checkpoints->every(fn (array $cp) => $cp['kind'] === null)) {
            return [];
        }

        $lastChecked = $this->lastCheckedAt($social->map(fn (array $a) => $a['subject']->id)->all());
        $out = [];

        foreach ($social as $account) {
            /** @var SocialAccount $subject */
            $subject = $account['subject'];

            foreach ($checkpoints as $cp) {
                if ($cp['kind'] === null) {
                    continue;
                }

                $since = $lastChecked[$subject->id.'|'.$cp['id']] ?? today();
                $count = $this->newActivity($subject, $cp['kind'], $since);

                if ($count !== null) {
                    $out[$account['key']][$cp['id']] = [
                        'count' => $count,
                        'label' => $count === 0
                            ? 'Nothing new'
                            : $count.' '.($cp['kind'] === 'messages'
                                ? str('new chat')->plural($count)
                                : str('new comment')->plural($count)),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * When each account's checkpoint was last ticked, in one query -- this
     * runs on every poll, so not once per tile.
     *
     * @param  list<int>  $accountIds
     * @return array<string, Carbon>  keyed "accountId|checkpointId"
     */
    private function lastCheckedAt(array $accountIds): array
    {
        return RoutineOccurrence::query()
            ->where('subject_type', Routine::SUBJECT_SOCIAL)
            ->whereIn('subject_id', $accountIds)
            ->where('status', RoutineOccurrence::STATUS_DONE)
            ->groupBy('subject_id', 'checkpoint_id')
            ->selectRaw('subject_id, checkpoint_id, max(completed_at) as last_at')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->subject_id.'|'.((int) $row->checkpoint_id) => Carbon::parse($row->last_at),
            ])
            ->all();
    }

    /**
     * Distinct people who messaged, or comments by anyone but the account,
     * since $since. Null when the account has no such feed at all.
     *
     * Cached for a minute: every open Inbox Check polls, and the answer moves
     * at the speed of Instagram, not of the poll.
     */
    private function newActivity(SocialAccount $account, string $kind, Carbon $since): ?int
    {
        $key = 'inbox-desk-activity:'.$account->id.':'.$kind.':'.$since->timestamp;

        return Cache::remember($key, 60, function () use ($account, $kind, $since) {
            $events = SocialWebhookEvent::query()
                ->where(fn (Builder $q) => $q
                    ->where('social_account_id', $account->id)
                    ->orWhere('external_id', $account->platform_user_id))
                ->when(
                    $kind === 'comments',
                    fn (Builder $q) => $q->where('field', 'comments'),
                    fn (Builder $q) => $q->where(fn (Builder $f) => $f->whereNull('field')->orWhere('field', '')),
                );

            $flowing = (clone $events)
                ->where('received_at', '>=', now()->subDays(self::ACTIVITY_FEED_DAYS))
                ->exists();

            if (! $flowing) {
                return null;
            }

            $self = (string) $account->platform_user_id;
            $payloads = (clone $events)->where('received_at', '>', $since)->limit(2000)->pluck('payload');

            if ($kind === 'comments') {
                return $payloads
                    ->filter(fn ($p) => is_array($p) && (string) data_get($p, 'value.from.id') !== $self)
                    ->count();
            }

            return $payloads
                ->filter(fn ($p) => is_array($p)
                    && isset($p['message'])
                    && empty($p['message']['is_echo'])
                    && (string) data_get($p, 'sender.id') !== $self)
                ->map(fn (array $p) => (string) data_get($p, 'sender.id'))
                ->unique()
                ->count();
        });
    }

    /**
     * The reply-count field for one checkpoint, if the routine has one: the
     * checkpoint's own number field first, then a shared one.
     *
     * @return array{key: string, label: string}|null
     */
    private function countField(Routine $routine, ?int $checkpointId): ?array
    {
        $numbers = $routine->fields->where('type', RoutineField::TYPE_NUMBER);

        $field = $numbers->first(fn (RoutineField $f) => $checkpointId && (int) $f->checkpoint_id === $checkpointId)
            ?? $numbers->first(fn (RoutineField $f) => $f->checkpoint_id === null);

        return $field ? ['key' => $field->key, 'label' => $field->label] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function countValues(RoutineOccurrence $row, mixed $count): array
    {
        $field = $this->countField($row->routine, $row->checkpoint_id);

        if (! $field || ! is_numeric($count)) {
            return [];
        }

        return [$field['key'] => max(0, min(9999, (int) $count))];
    }

    private function kindOf(?string $name): ?string
    {
        $name = mb_strtolower((string) $name);

        return match (true) {
            str_contains($name, 'comment') => 'comments',
            str_contains($name, 'dm') || str_contains($name, 'message') || str_contains($name, 'inbox') => 'messages',
            default => null,
        };
    }

    private function shortName(?string $name): string
    {
        return match ($this->kindOf($name)) {
            'messages' => 'DMs',
            'comments' => 'Comments',
            default => $name ?: 'Check',
        };
    }

    private function accountName(mixed $subject): ?string
    {
        return $subject instanceof SocialAccount ? $subject->client?->name : null;
    }

    private function profileUrl(mixed $subject): ?string
    {
        if ($subject instanceof SocialAccount && filled($subject->username)) {
            return 'https://www.instagram.com/'.rawurlencode($subject->username).'/';
        }

        return null;
    }
}
