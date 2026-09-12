<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\EmployeeRecognition;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Awards the recognition the system can see for itself.
 *
 * Two deliberate limits on what this will ever do:
 *
 * It only ever awards. Nothing here records a miss, and there is no
 * leaderboard reading it -- a nightly job that quietly builds a case against
 * somebody is a different product, and a worse one. Somebody having a bad
 * fortnight simply earns nothing, which is between them and their manager.
 *
 * And it only claims what is actually measurable. "Delivered on time" is
 * judged on the day a piece's own published_date falls, by looking at where
 * it got to -- not by comparing a planned date to an actual one, because
 * content_items has exactly one date and it serves as both. That means a
 * piece whose date was pushed in Notion before the original passed is simply
 * never assessed; it is not counted as late, and not counted as on time.
 * Erring toward silence is the right side to err on for something whose only
 * output is praise.
 */
class RecognitionAwarder
{
    /** Runs every day since the last one it managed, so a quiet week self-heals. */
    public const MAX_CATCHUP_DAYS = 14;

    public function run(?Carbon $upTo = null): int
    {
        $upTo = ($upTo ?? today())->copy()->startOfDay();

        // Yesterday backwards: today's timesheets are not late until the day
        // is out, and today's content still has the day to land.
        $awarded = 0;

        for ($offset = 1; $offset <= self::MAX_CATCHUP_DAYS; $offset++) {
            $day = $upTo->copy()->subDays($offset);
            $awarded += $this->timesheetOnTime($day);
            $awarded += $this->contentOnTime($day);
        }

        return $awarded;
    }

    /**
     * Logged the day's work without backdating it.
     *
     * `was_backdated` is stamped at creation (see TimesheetEntry::isLateFor),
     * so this reads what was actually true at the time rather than
     * re-deriving it later -- by which point every entry in the system looks
     * backdated.
     */
    private function timesheetOnTime(Carbon $day): int
    {
        $entries = TimesheetEntry::query()
            ->whereDate('worked_on', $day->toDateString())
            ->counted()
            ->get(['user_id', 'was_backdated']);

        if ($entries->isEmpty()) {
            return 0;
        }

        $awarded = 0;

        foreach ($entries->groupBy('user_id') as $userId => $own) {
            // One backdated entry is enough: the day was not filed on time,
            // it was reconstructed afterwards.
            if ($own->contains(fn (TimesheetEntry $entry) => (bool) $entry->was_backdated)) {
                continue;
            }

            $awarded += $this->award(
                (int) $userId,
                EmployeeRecognition::KIND_TIMESHEET_ON_TIME,
                'timesheet:'.$day->toDateString(),
                $day,
                1,
                'Logged '.$day->format('j M').' on the day.',
            );
        }

        return $awarded;
    }

    /**
     * A piece that had reached the finish line by the date it was due out.
     *
     * Judged on the item's own published_date, once. Anything still mid-flight
     * that day earns nothing and is not recorded as anything.
     */
    private function contentOnTime(Carbon $day): int
    {
        $items = ContentItem::query()
            ->whereNotNull('assigned_user_id')
            ->whereDate('published_date', $day->toDateString())
            ->whereIn('status', ['Published', 'Delivered'])
            ->get(['id', 'title', 'assigned_user_id', 'status']);

        $awarded = 0;

        foreach ($items as $item) {
            $title = trim((string) $item->title) ?: 'A piece';

            $awarded += $this->award(
                (int) $item->assigned_user_id,
                EmployeeRecognition::KIND_CONTENT_ON_TIME,
                'content:'.$item->id,
                $day,
                2,
                Str::limit($title, 80).' — out on time.',
            );
        }

        return $awarded;
    }

    /**
     * Inserts one award, or does nothing at all.
     *
     * firstOrCreate against the (user_id, source_key) unique index is what
     * makes the whole run safe to repeat -- the catch-up loop above walks the
     * same fortnight every day on purpose.
     *
     * Clients are skipped: assigned_user_id is a staff column, but a bad
     * Notion sync putting a client login in it should not quietly hand a
     * client a staff recognition row.
     */
    private function award(int $userId, string $kind, string $sourceKey, Carbon $day, int $points, string $note): int
    {
        $user = User::query()->staff()->find($userId);

        if ($user === null) {
            return 0;
        }

        $record = EmployeeRecognition::firstOrCreate(
            ['user_id' => $userId, 'source_key' => $sourceKey],
            ['kind' => $kind, 'earned_on' => $day->toDateString(), 'points' => $points, 'note' => $note],
        );

        return $record->wasRecentlyCreated ? 1 : 0;
    }
}
