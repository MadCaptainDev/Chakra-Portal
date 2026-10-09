<?php

namespace App\Services;

use App\Models\DailyCheck;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\DailyCheckPending;
use App\Support\DailyReport;
use App\Support\TimesheetVenture;
use App\Tools\Clients;
use App\Tools\CreateTodo;
use App\Tools\ToolException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns the editing team's daily WhatsApp messages into the portal's own
 * records, so nobody types the day twice.
 *
 *  - A task list makes each line a to-do for the person it is under (by
 *    first name), filed against the client the line starts with. Sent
 *    again in the evening with "(Completed)" or "(Edit in progress)", the
 *    same lines move those to-dos on -- matched by their wording -- and any
 *    new line is added. Lines left unmarked stay open.
 *  - An EOD check is kept as that day's checklist; anything crossed off is
 *    pushed to the admins.
 *
 * To-dos are made through the same CreateTodo tool as everywhere else and
 * moved with Todo::moveTo(), so their history reads exactly as if they had
 * been made by hand. Returns the reply sent back on WhatsApp.
 */
class DailyReportImporter
{
    /** How far back an evening line looks for the morning's to-do. */
    private const MATCH_DAYS = 7;

    public function apply(string $text, User $sender): string
    {
        $report = DailyReport::parse($text);

        return $report['check'] !== null
            ? $this->check($report, $text, $sender)
            : $this->tasks($report, $sender);
    }

    private function tasks(array $report, User $sender): string
    {
        $date = $report['date'];
        $lines = [];
        $unknown = [];

        foreach ($report['people'] as $name => $tasks) {
            $person = $this->person($name);
            if ($person === null) {
                $unknown[] = Str::title(strtolower($name));

                continue;
            }

            $made = $done = $going = $open = 0;
            foreach ($tasks as $task) {
                $todo = Todo::query()
                    ->where('user_id', $person->id)
                    ->whereDate('starts_on', '>=', $date->copy()->subDays(self::MATCH_DAYS))
                    ->whereDate('starts_on', '<=', $date)
                    ->get()
                    ->first(fn (Todo $t) => self::same($t->title, $task['title']));

                if ($todo === null) {
                    try {
                        $created = (new CreateTodo)->handle([
                            'title' => $task['title'],
                            'person' => $person->email,
                            'client' => $this->client($task['client']),
                            'starts_on' => $date->toDateString(),
                            'due_on' => $date->toDateString(),
                            'notes' => 'From the daily task message on WhatsApp.',
                        ], $sender);
                    } catch (ToolException $e) {
                        Log::warning('Daily report line not filed.', ['line' => $task['line'], 'error' => $e->getMessage()]);

                        continue;
                    }
                    $todo = Todo::find($created['id']);
                    $made++;
                }

                if ($task['status'] !== null && $todo->status !== $task['status'] && $todo->status !== Todo::STATUS_COMPLETED) {
                    $todo->moveTo($task['status'], $sender, null);
                }

                match ($todo->fresh()->status) {
                    Todo::STATUS_COMPLETED => $done++,
                    Todo::STATUS_STARTED => $going++,
                    default => $open++,
                };
            }

            $lines[] = '• '.$person->name.': '.count($tasks).' '.Str::plural('task', count($tasks))
                .($made ? " ({$made} new)" : '')
                .' — '.implode(', ', array_filter([
                    $done ? "{$done} done" : null,
                    $going ? "{$going} in progress" : null,
                    $open ? "{$open} open" : null,
                ]));
        }

        if ($lines === [] && $unknown === []) {
            return 'I could not find any tasks in that. Put each person\'s name in capitals with a colon (SANJAI :) and number the tasks under it.';
        }

        return "Got it — {$date->format('j M')} to-dos updated:\n".implode("\n", $lines)
            .($unknown ? "\nNot on the team list, so skipped: ".implode(', ', $unknown).'.' : '');
    }

    private function check(array $report, string $text, User $sender): string
    {
        $c = $report['check'];
        $check = DailyCheck::updateOrCreate(
            ['date' => $report['date']->toDateString(), 'team' => Str::limit($c['team'], 80, '')],
            ['items' => $c['items'], 'status' => $c['status'], 'reported_by_id' => $sender->id, 'message' => $text],
        );

        $missed = $check->missed();
        if ($missed !== []) {
            try {
                Notification::send(User::query()->where('role', User::ROLE_ADMIN)->get(), new DailyCheckPending($check));
            } catch (Throwable $e) {
                Log::error('Daily check push failed.', ['daily_check_id' => $check->id, 'error' => $e->getMessage()]);
            }
        }

        $ok = count($c['items']) - count($missed);

        return "Got it — {$check->team} EOD check for {$report['date']->format('j M')}: {$ok} of ".count($c['items']).' done.'
            .($missed ? "\nStill open: ".collect($missed)->map(fn ($i) => $i['label'].($i['note'] ? " ({$i['note']})" : ''))->implode('; ').'.' : '');
    }

    /** "SANJAI" → the one person on staff whose name starts so. */
    private function person(string $name): ?User
    {
        $matches = User::query()->staff()->where('name', 'like', trim($name).'%')->get();

        return $matches->count() === 1 ? $matches->first() : $matches->first(fn (User $u) => strcasecmp($u->name, trim($name)) === 0);
    }

    /**
     * "SVA" → SVA Silks, "Suryas" → Surya's Restaurant, "Mahavir" → Mahavir
     * Timbers…; anything unknown is filed as all/multiple clients, its name
     * still in the to-do's title.
     */
    private function client(string $token): string
    {
        if (trim($token) === '') {
            return TimesheetVenture::ALL_CLIENTS;
        }
        try {
            return Clients::match($token);
        } catch (ToolException) {
            $starts = array_values(array_filter(TimesheetVenture::allowedValues(), fn ($v) => Str::startsWith(Str::lower($v), Str::lower(trim($token)))));

            return count($starts) === 1 ? $starts[0] : TimesheetVenture::ALL_CLIENTS;
        }
    }

    private static function same(string $a, string $b): bool
    {
        $norm = fn ($s) => Str::lower(preg_replace('/[^\pL\pN]+/u', '', $s));

        return $norm($a) === $norm($b);
    }
}
