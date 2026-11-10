<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VentureAlertSetting;
use App\Models\VentureWatch;
use App\Notifications\VentureUpdate;
use App\Services\WhatsappSender;
use App\Support\VentureBoard;
use App\Support\WhatsappServiceWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tell each admin about the ventures they watch on the dashboard:
 *
 *   moves     a video became Ready, went out (Published/Delivered), or was
 *             shot (To Be Edited) -- one message per venture per run
 *   low       fewer videos in hand than the person's threshold; told once,
 *             and again only after it has recovered and dropped again
 *   summary   the morning roll-call, at the person's chosen time
 *
 * Moves are found by comparing each item's status with the one recorded on
 * the last run (venture_item_states), so they work whichever way the change
 * arrived -- the planner, the Notion webhook or the nightly sync. The very
 * first run only records; it never announces the whole back catalogue.
 *
 * Every message goes to push, the app inbox and the portal bell, and also as
 * a WhatsApp text where the person has asked for it and Meta's 24-hour
 * window is open. Scheduled every five minutes.
 */
class VentureAlerts extends Command
{
    protected $signature = 'ventures:alerts {--summary-now : Send everyone\'s summary now, whatever the time}';

    protected $description = 'Notify admins about the ventures they watch: videos ready or out, stock running low, and the morning summary.';

    private const MOVES = [
        'ready' => ['Video Ready'],
        'out' => ['Published', 'Delivered'],
        'shot' => ['To Be Edited'],
    ];

    public function handle(): int
    {
        $watches = VentureWatch::query()->with('user')->get()->filter(fn (VentureWatch $w) => $w->user && ! $w->user->isClient());
        if ($watches->isEmpty()) {
            $this->info('Nobody watches a venture yet.');

            return self::SUCCESS;
        }

        $settings = VentureAlertSetting::query()->whereIn('user_id', $watches->pluck('user_id'))->get()->keyBy('user_id');
        $setting = fn (int $userId) => $settings[$userId] ?? $settings[$userId] = VentureAlertSetting::for(User::find($userId));

        $sent = $this->moves($watches, $setting);
        $sent += $this->lowStock($watches, $setting);
        $sent += $this->summaries($watches, $setting);

        $this->info($sent > 0 ? "{$sent} notification(s) sent." : 'Nothing new.');

        return self::SUCCESS;
    }

    /** @param  Collection<int, VentureWatch>  $watches */
    private function moves(Collection $watches, callable $setting): int
    {
        $ventures = $watches->pluck('venture')->unique()->values()->all();
        $items = VentureBoard::items()->whereIn('venture', $ventures)->get(['id', 'venture', 'title', 'status']);
        $seen = DB::table('venture_item_states')->whereIn('content_item_id', $items->pluck('id'))->pluck('status', 'content_item_id');
        $firstRun = ! DB::table('venture_item_states')->exists();

        $asked = collect($ventures)->mapWithKeys(fn (string $v) => [VentureBoard::fold($v) => $v]);
        $news = []; // venture => kind => titles
        foreach ($items as $item) {
            $venture = $asked[VentureBoard::fold((string) $item->venture)] ?? $item->venture;
            $before = $seen[$item->id] ?? null;
            if ($firstRun || ! $seen->has($item->id) || $before === $item->status) {
                continue;
            }
            foreach (self::MOVES as $kind => $statuses) {
                // Published -> Delivered (or back) is not news.
                if (in_array($item->status, $statuses, true) && ! in_array($before, $statuses, true)) {
                    $news[$venture][$kind][] = $item->title ?: 'Untitled';
                }
            }
        }

        // Record what everything looks like now, for the next run.
        $now = now();
        foreach ($items->chunk(500) as $chunk) {
            DB::table('venture_item_states')->upsert(
                $chunk->map(fn ($i) => ['content_item_id' => $i->id, 'status' => $i->status, 'seen_at' => $now])->values()->all(),
                ['content_item_id'], ['status', 'seen_at'],
            );
        }

        $sent = 0;
        foreach ($news as $venture => $kinds) {
            foreach ($watches->where('venture', $venture) as $watch) {
                $s = $setting($watch->user_id);
                $lines = [];
                foreach ($kinds as $kind => $titles) {
                    $wanted = ['ready' => $s->notify_ready, 'out' => $s->notify_published, 'shot' => $s->notify_shot][$kind];
                    if ($wanted) {
                        $verb = ['ready' => 'ready to post', 'out' => 'out', 'shot' => 'shot, now to edit'][$kind];
                        $lines[] = count($titles).' '.Str::plural('video', count($titles)).' '.$verb.': '.$this->list($titles);
                    }
                }
                if ($lines) {
                    $this->tell($watch->user, $s, $venture, implode("\n", $lines), 'venture-move-'.md5($venture));
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** @param  Collection<int, VentureWatch>  $watches */
    private function lowStock(Collection $watches, callable $setting): int
    {
        $rows = collect(VentureBoard::build($watches->pluck('venture')->unique()->values()->all(), now())['rows'])->keyBy('venture');
        $sent = 0;

        foreach ($watches as $watch) {
            $s = $setting($watch->user_id);
            $row = $rows[$watch->venture] ?? null;
            if (! $row) {
                continue;
            }

            if ($row['in_hand'] >= $s->low_stock) {
                if ($watch->low_alerted_at) {
                    $watch->forceFill(['low_alerted_at' => null])->save();
                }
                continue;
            }
            if ($watch->low_alerted_at || ! $s->notify_low) {
                continue;
            }

            // Claimed first, so two runs at once never both send.
            if (! VentureWatch::query()->whereKey($watch->id)->whereNull('low_alerted_at')->update(['low_alerted_at' => now()])) {
                continue;
            }

            $toShoot = $row['stages']['to_shoot'];
            $body = $row['in_hand'] === 0
                ? 'No videos in hand.'
                : 'Only '.$row['in_hand'].' '.Str::plural('video', $row['in_hand']).' in hand ('.$row['stages']['ready'].' ready).';
            $body .= $toShoot > 0 ? ' '.$toShoot.' waiting to be shot.' : ' Nothing waiting to be shot either: plan a shoot.';
            if ($row['planned_until']) {
                $body .= ' Planned until '.\Illuminate\Support\Carbon::parse($row['planned_until'])->format('j M').'.';
            }

            $this->tell($watch->user, $s, $watch->venture.' is running low', $body, 'venture-low-'.md5($watch->venture));
            $sent++;
        }

        return $sent;
    }

    /** @param  Collection<int, VentureWatch>  $watches */
    private function summaries(Collection $watches, callable $setting): int
    {
        $sent = 0;

        foreach ($watches->groupBy('user_id') as $userId => $mine) {
            $s = $setting((int) $userId);
            $due = $this->option('summary-now')
                || ($s->daily_summary && now()->format('H:i') >= $s->summary_at && ! $s->summary_sent_on?->isToday());
            if (! $due) {
                continue;
            }
            if (! $this->option('summary-now')
                && ! VentureAlertSetting::query()->whereKey($s->id)->where(fn ($q) => $q->whereNull('summary_sent_on')->orWhere('summary_sent_on', '<', today()->toDateString()))
                    ->update(['summary_sent_on' => today()->toDateString()])) {
                continue;
            }

            $board = VentureBoard::build($mine->sortBy('sort_order')->pluck('venture')->all(), now(), $s->low_stock);
            $lines = collect($board['rows'])->map(fn (array $r) => ($r['health'] === 'ok' ? '' : '⚠ ').$r['venture'].': '
                .$r['in_hand'].' in hand ('.$r['stages']['ready'].' ready, '.$r['stages']['scheduled'].' scheduled), '
                .$r['stages']['to_shoot'].' to shoot, '.$r['out_month'].' out this month'
                .($r['overdue'] ? ', '.$r['overdue'].' past their date' : ''));
            $t = $board['totals'];
            $head = $t['in_hand'].' videos in hand across '.count($board['rows']).' ventures, '.$t['out_month'].' out this month.';

            $this->tell($mine->first()->user, $s, 'Your ventures today', $head."\n".$lines->implode("\n"), 'venture-summary');
            $sent++;
        }

        return $sent;
    }

    private function tell(User $user, VentureAlertSetting $s, string $title, string $body, string $tag): void
    {
        try {
            $user->notify(new VentureUpdate($title, $body, $tag));
        } catch (Throwable $e) {
            Log::warning('Venture alert push failed.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        if (! $s->whatsapp || blank($user->phone)) {
            return;
        }
        try {
            if (WhatsappServiceWindow::isOpen((string) $user->phone)) {
                WhatsappSender::make()->sendText($user->phone, '*'.$title."*\n".$body."\n".route('dashboard'));
            }
        } catch (Throwable $e) {
            Log::warning('Venture alert WhatsApp failed.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    /** @param  list<string>  $titles */
    private function list(array $titles): string
    {
        $shown = array_slice($titles, 0, 3);

        return implode(', ', $shown).(count($titles) > 3 ? ' and '.(count($titles) - 3).' more' : '');
    }
}
