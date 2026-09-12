<?php

namespace App\Services\WhatsappFlow;

use App\Models\User;
use App\Models\WhatsappFlow;
use App\Services\WhatsappSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs `scheduled` flows once a day, at or after the time they name.
 *
 * Every other trigger is reactive -- somebody messages, a flow answers. This
 * is the only one that starts on its own, which makes it the only one that
 * can talk to somebody who never asked it to. Three things keep that in
 * hand:
 *
 * - Once per day, enforced by `last_run_on` on the flow row rather than a
 *   cache key, and claimed *before* the first message goes out. A crash
 *   halfway through a run loses the rest of that day's recipients; it does
 *   not re-send to the ones already reached.
 * - Never backfilled. A flow whose time passed while nobody was signed in
 *   runs at the next request, not once per missed day -- "your 8am briefing"
 *   is worthless at 4pm and worse three times over. Anything older than
 *   today is simply skipped.
 * - Internal audiences only. Scheduling messages to clients is a campaign,
 *   and campaigns already have their own screen, their own consent handling
 *   and their own template discipline.
 *
 * The 24-hour rule applies here as everywhere: a free-form Send Message to
 * somebody who has not written in the last day is refused by Meta. A
 * scheduled flow should open with a Send Template node. The editor says so;
 * this class cannot enforce it, because whether a given number is inside its
 * window is not knowable until the send is attempted.
 */
class ScheduledFlowRunner
{
    public function __construct(private readonly FlowEngine $engine) {}

    /** @return int messages started */
    public function run(): int
    {
        $due = WhatsappFlow::query()
            ->where('is_active', true)
            ->where('trigger_type', 'scheduled')
            ->get()
            ->filter(fn (WhatsappFlow $flow) => $this->isDue($flow));

        $started = 0;

        foreach ($due as $flow) {
            // Claimed first: whatever happens to the sends below, this flow
            // does not come due again today.
            $flow->forceFill(['last_run_on' => today()->toDateString()])->save();

            foreach ($this->recipients($flow) as $waId) {
                try {
                    $this->engine->startScheduled($flow, $waId);
                    $started++;
                } catch (Throwable $e) {
                    // One unreachable number must not cost the rest of the
                    // team their briefing.
                    Log::warning('Scheduled WhatsApp flow failed for one recipient', [
                        'flow_id' => $flow->id,
                        'wa_id' => $waId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $started;
    }

    /**
     * Due when today's named time has passed and it has not already run
     * today. Days are optional; an empty list means every day.
     */
    private function isDue(WhatsappFlow $flow): bool
    {
        if ($flow->last_run_on !== null && $flow->last_run_on->isSameDay(today())) {
            return false;
        }

        $time = (string) data_get($flow->trigger_config, 'time', '');

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts)) {
            return false;
        }

        $days = (array) data_get($flow->trigger_config, 'days', []);

        if ($days !== [] && ! in_array(strtolower(now()->format('D')), array_map('strtolower', $days), true)) {
            return false;
        }

        return now()->greaterThanOrEqualTo(today()->setTime((int) $parts[1], (int) $parts[2]));
    }

    /**
     * @return list<string>
     */
    private function recipients(WhatsappFlow $flow): array
    {
        $audience = (string) data_get($flow->trigger_config, 'audience', 'admins');

        $users = User::query()
            ->staff()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->when($audience === 'admins', fn ($query) => $query->where('role', User::ROLE_ADMIN))
            ->get();

        return $users
            ->map(fn (User $user) => WhatsappSender::normalise($user->phone))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
