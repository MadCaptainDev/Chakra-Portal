<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Models\ShootEvent;
use App\Models\User;
use App\Notifications\ShootRunningLate;
use App\Services\WhatsappSender;
use App\Support\WhatsappServiceWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tell the admins when a shoot day is slipping. Four kinds, each sent once
 * per shoot (its own *_alert_at column, claimed before sending so two runs
 * never send twice):
 *
 *   start    not started some minutes after the booked time
 *   overrun  still rolling some minutes after the booked end
 *   footage  wrapped hours ago and the footage is not on a drive
 *   kit      kit still out late in the evening of the shoot's day
 *
 * Push and the in-app inbox always. WhatsApp only as a plain message to
 * admins whose 24-hour window is open -- there is no approved template for
 * these yet, and a free-form message outside the window is refused by Meta.
 *
 * Thresholds live in config/shoots.php. Scheduled every five minutes.
 */
class ShootLateAlerts extends Command
{
    protected $signature = 'shoots:late-alerts';

    protected $description = 'Alert admins when a shoot starts late, overruns, or leaves footage or kit outstanding.';

    public function handle(): int
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        $sent = 0;

        foreach ($this->late() as [$shoot, $kind, $column, $message]) {
            // Claim it first, so two runs never alert twice.
            if (! Shoot::query()->whereKey($shoot->id)->whereNull($column)->update([$column => now()])) {
                continue;
            }

            ShootEvent::record($shoot, null, ShootEvent::LATE_ALERT, $message, ['kind' => $kind]);
            $this->tell($admins, $shoot, $kind, $message);
            $this->line("{$kind}: {$shoot->title}");
            $sent++;
        }

        $this->info($sent > 0 ? "{$sent} late alert(s)." : 'Nothing late.');

        return self::SUCCESS;
    }

    /**
     * Every lateness that has not been told yet.
     *
     * @return list<array{0: Shoot, 1: string, 2: string, 3: string}>
     */
    private function late(): array
    {
        $late = [];
        $cfg = (array) config('shoots.late');

        // Only today's and yesterday's shoots: this is an alarm, not a backlog report.
        $shoots = Shoot::query()
            ->where('status', '!=', Shoot::STATUS_CANCELLED)
            ->whereBetween('starts_at', [now()->subDay()->startOfDay(), now()])
            ->with(['client:id,name', 'kits', 'crew.user:id,name'])
            ->get();

        foreach ($shoots as $shoot) {
            $who = $shoot->crew->map(fn ($c) => $c->user?->name)->filter()->implode(', ') ?: 'no crew listed';

            if ($shoot->late_start_alert_at === null && $shoot->started_at === null && $shoot->hasStartTime()
                && $shoot->starts_at->isToday()
                && $shoot->starts_at->lte(now()->subMinutes((int) $cfg['start_minutes']))) {
                $mins = (int) round($shoot->starts_at->diffInMinutes(now(), true));
                $state = $shoot->hasReached() ? 'crew on location' : ($shoot->hasDeparted() ? 'crew on the way' : 'crew have not left');
                $late[] = [$shoot, 'start', 'late_start_alert_at',
                    "{$shoot->title} has not started. Booked {$shoot->starts_at->format('g:i A')}, {$mins} min late ({$state}). Crew: {$who}."];
            }

            $end = $shoot->expectedEnd();
            if ($shoot->overrun_alert_at === null && $shoot->isInProgress() && $end
                && $end->lte(now()->subMinutes((int) $cfg['overrun_minutes']))) {
                $mins = (int) round($end->diffInMinutes(now(), true));
                $late[] = [$shoot, 'overrun', 'overrun_alert_at',
                    "{$shoot->title} is still rolling, {$mins} min past its ".($shoot->ends_at ? 'booked end' : 'expected end')." ({$end->format('g:i A')}). Crew: {$who}."];
            }

            if ($shoot->footage_alert_at === null && $shoot->hasWrapped() && ! $shoot->hasFootageFiled()
                && $shoot->finished_at->lte(now()->subHours((int) $cfg['footage_hours']))) {
                $late[] = [$shoot, 'footage', 'footage_alert_at',
                    "{$shoot->title} wrapped at {$shoot->finished_at->format('g:i A')} and the footage is not on a drive yet. Crew: {$who}."];
            }

            if ($shoot->kit_alert_at === null && $shoot->hasKitOut() && ! $shoot->isInProgress()
                && now()->gte($shoot->starts_at->copy()->startOfDay()->addHours((int) $cfg['kit_hour']))) {
                $out = $shoot->kits->filter->isCheckedOut()->count();
                $late[] = [$shoot, 'kit', 'kit_alert_at',
                    "{$shoot->title}: {$out} kit ".str('item')->plural($out).' not returned. Crew: '.$who.'.'];
            }
        }

        return $late;
    }

    /** @param  Collection<int, User>  $admins */
    private function tell(Collection $admins, Shoot $shoot, string $kind, string $message): void
    {
        try {
            Notification::send($admins, new ShootRunningLate($shoot, $kind, $message));
        } catch (Throwable $e) {
            Log::warning('Shoot late alert push failed.', ['shoot_id' => $shoot->id, 'error' => $e->getMessage()]);
        }

        $withPhone = $admins->filter(fn (User $u) => filled($u->phone));

        foreach (WhatsappSender::onePerPhone($withPhone) as $admin) {
            try {
                if (WhatsappServiceWindow::isOpen((string) $admin->phone)) {
                    WhatsappSender::make()->sendText($admin->phone, $message."\n".route('shoots.show', $shoot));
                }
            } catch (Throwable $e) {
                Log::warning('Shoot late alert WhatsApp failed.', ['shoot_id' => $shoot->id, 'user_id' => $admin->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
