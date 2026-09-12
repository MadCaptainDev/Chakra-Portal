<?php

namespace App\Support;

use App\Models\EquipmentItem;
use App\Models\ShootCrew;
use App\Models\User;
use App\Models\WhatsappFlowSession;
use App\Services\WhatsappSender;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Crew-specific WhatsApp content for automation nodes.
 *
 * The staff-side twin of ClientPortalContent: same shape, same contract, one
 * deliberate difference in who it answers to. The crew are in WhatsApp all
 * day and in the portal rarely, so the two things they are actually chased
 * about -- "did you see the call time" and "what's broken" -- are answerable
 * without opening it.
 *
 * Everything here is scoped to the person the number belongs to. There is no
 * path through this class that reads or writes another person's shoots, and
 * an unrecognised number gets a flat refusal rather than a hint about what
 * exists.
 */
class CrewPortal
{
    /**
     * The staff member this session belongs to, or null.
     *
     * Prefers the `crew.id` FlowEngine injects on a recognised number, and
     * falls back to matching the wa_id itself -- the same two-step
     * ClientPortalContent::clientForSession() uses, for the same reason: a
     * session that began before the variable existed still resolves.
     */
    public static function userForSession(WhatsappFlowSession $session): ?User
    {
        $userId = data_get($session->variables, 'crew.id');

        return $userId ? User::query()->staff()->find($userId) : User::findForWhatsappCrew($session->wa_id);
    }

    /**
     * The next few shoots this person is actually crewed on, with their own
     * call time -- not the shoot's start, which is a different time for sound
     * and camera and is the whole reason shoot_crew.call_time exists.
     */
    public static function myShoots(User $user): string
    {
        $rows = self::upcomingCrewRows($user);

        if ($rows->isEmpty()) {
            return "You're not crewed on anything coming up.";
        }

        $lines = ['Your next shoots:', ''];

        foreach ($rows as $row) {
            $shoot = $row->shoot;
            $lines[] = '• '.$shoot->title.' — '.$shoot->starts_at->format('D j M');

            $detail = '  Call '.self::callTimeLabel($row);
            if ($shoot->location) {
                $detail .= ' · '.$shoot->location;
            }
            $lines[] = $detail;

            if ($row->role) {
                $lines[] = '  Role: '.$row->role;
            }

            $lines[] = $row->isConfirmed() ? '  ✓ Confirmed' : '  Not confirmed yet';
            $lines[] = '';
        }

        $lines[] = 'Reply CONFIRM to confirm the next one.';

        return trim(implode("\n", $lines));
    }

    /**
     * Confirms the soonest unconfirmed shoot.
     *
     * One at a time and always the nearest, because that is the one a
     * confirmation is being chased for. Confirming something already
     * confirmed says so rather than erroring -- a second tap on a bad
     * connection is the normal case, not a mistake worth a scolding.
     */
    public static function confirmNextShoot(User $user): string
    {
        $rows = self::upcomingCrewRows($user);

        if ($rows->isEmpty()) {
            return "You're not crewed on anything coming up, so there's nothing to confirm.";
        }

        $next = $rows->first(fn (ShootCrew $row) => ! $row->isConfirmed());

        if ($next === null) {
            return "You've already confirmed everything coming up. Nothing else needed.";
        }

        $next->forceFill(['confirmed_at' => now()])->save();

        $shoot = $next->shoot;
        $when = $shoot->starts_at->format('D j M');
        $call = self::callTimeLabel($next);

        $reply = "Confirmed — {$shoot->title}, {$when}, call {$call}.";

        if ($shoot->location) {
            $reply .= "\nLocation: {$shoot->location}";
        }

        return $reply;
    }

    /**
     * Flags a piece of kit as damaged, in repair or lost.
     *
     * Matching runs over the kit on this person's own recent and upcoming
     * shoots first: it is a far smaller set than the whole register, and it
     * is almost always what somebody texting "tripod is broken" means. Only
     * if nothing there matches does it widen to everything the studio owns.
     *
     * Never guesses between two candidates -- an ambiguous message gets the
     * shortlist back and changes nothing, because quietly retiring the wrong
     * camera is a considerably worse outcome than one more message.
     */
    public static function flagKit(User $user, string $message, string $status = EquipmentItem::STATUS_DAMAGED): string
    {
        $needle = self::stripFlagWords($message);

        if (Str::length($needle) < 3) {
            return "Tell me which item, like: broken tripod.";
        }

        $matches = self::matchItems($user, $needle);

        if ($matches->isEmpty()) {
            return "I couldn't find kit matching \"{$needle}\". Check the name on the register and try again.";
        }

        if ($matches->count() > 1) {
            $names = $matches->take(5)->map(fn (EquipmentItem $item) => '• '.$item->name)->implode("\n");

            return "That matches more than one thing:\n{$names}\n\nReply with the exact name.";
        }

        /** @var EquipmentItem $item */
        $item = $matches->first();
        $label = EquipmentItem::STATUSES[$status] ?? 'Damaged';

        $item->forceFill([
            'status' => $status,
            'status_note' => $label.' — flagged by '.$user->name.' on WhatsApp, '.now()->format('j M'),
        ])->save();

        return "Flagged: {$item->name} is now marked {$label}. It won't show as free on the next shoot.";
    }

    public static function sendToSession(WhatsappFlowSession $session, string $body): void
    {
        WhatsappSender::make()->sendText($session->wa_id, $body);
    }

    /**
     * This person's crew rows on shoots that haven't happened yet.
     *
     * @return Collection<int, ShootCrew>
     */
    private static function upcomingCrewRows(User $user): Collection
    {
        return ShootCrew::query()
            ->with('shoot')
            ->where('user_id', $user->id)
            ->whereHas('shoot', fn ($query) => $query->where('starts_at', '>=', now()->startOfDay()))
            ->get()
            ->filter(fn (ShootCrew $row) => $row->shoot !== null)
            ->sortBy(fn (ShootCrew $row) => $row->shoot->starts_at)
            ->take(5)
            ->values();
    }

    /**
     * What to actually tell somebody the call is.
     *
     * Their own call_time wins. Failing that the shoot's start does -- except
     * at exactly midnight, which is not a 00:00 call but a shoot Notion synced
     * as a date with no time on it (every synced shoot currently looks like
     * this). Printing "Call 12:00 AM" for those would be read as a real time
     * and is worse than admitting nobody has set one.
     */
    private static function callTimeLabel(ShootCrew $row): string
    {
        if (filled($row->call_time)) {
            $timestamp = strtotime((string) $row->call_time);

            if ($timestamp !== false) {
                return date('g:i A', $timestamp);
            }
        }

        $startsAt = $row->shoot?->starts_at;

        if ($startsAt === null || ($startsAt->hour === 0 && $startsAt->minute === 0)) {
            return 'time TBC';
        }

        return $startsAt->format('g:i A');
    }

    /**
     * Drops the words that say *what happened* so only the item name is left
     * to match on -- "the gimbal is broken" and "broken gimbal" have to reach
     * the same row.
     */
    private static function stripFlagWords(string $message): string
    {
        $noise = ['broken', 'damaged', 'damage', 'missing', 'lost', 'repair', 'faulty', 'not working',
            'is', 'are', 'the', 'my', 'a', 'an', 'and', 'kit', 'item', 'report', 'flag'];

        $words = preg_split('/\s+/', mb_strtolower(trim($message))) ?: [];

        return trim(implode(' ', array_filter(
            $words,
            fn (string $word) => ! in_array(trim($word, ".,!?"), $noise, true) && $word !== ''
        )));
    }

    /**
     * @return Collection<int, EquipmentItem>
     */
    private static function matchItems(User $user, string $needle): Collection
    {
        $onTheirShoots = EquipmentItem::query()
            ->active()
            ->whereHas('kits.shoot.crew', fn ($query) => $query->where('user_id', $user->id))
            ->get();

        $hits = self::filterByName($onTheirShoots, $needle);

        if ($hits->isNotEmpty()) {
            return $hits;
        }

        return self::filterByName(EquipmentItem::query()->active()->get(), $needle);
    }

    /**
     * An exact name match wins outright -- otherwise "Sony A7 M5" could never
     * be flagged while "Sony A7M5 Charger" also contains it.
     *
     * @param  Collection<int, EquipmentItem>  $items
     * @return Collection<int, EquipmentItem>
     */
    private static function filterByName(Collection $items, string $needle): Collection
    {
        $exact = $items->filter(fn (EquipmentItem $item) => mb_strtolower($item->name) === $needle);

        if ($exact->isNotEmpty()) {
            return $exact->values();
        }

        return $items
            ->filter(fn (EquipmentItem $item) => Str::contains(mb_strtolower($item->name), $needle)
                || Str::contains($needle, mb_strtolower($item->name)))
            ->values();
    }
}
