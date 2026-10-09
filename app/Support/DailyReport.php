<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reads the editing team's daily WhatsApp messages, as they already write
 * them. Two shapes:
 *
 *   A task list -- the morning "Daily Task:" and the evening update:
 *
 *       09.10.2026
 *       Daily Task:
 *       SANJAI :
 *       1.SVA - Sharara Kurti Set (Completed)
 *       4.SVA - Chettinad Cotton Frock (Edit in progress)
 *
 *   An end-of-day check:
 *
 *       EDITING TEAM | EOD CHECK*
 *       1. Videos Posted — ✅
 *       3. Final Outputs Moved to Main Disk — ❌(SANJAI BRO out pending)
 *       **Status: Pending
 *
 * Pure text in, plain arrays out; no database. DailyReportImporter does the
 * rest. Anything it does not recognise (the "IG POST BY" lines, "DONE") is
 * skipped rather than guessed at.
 */
final class DailyReport
{
    public const DONE = 'completed';

    public const IN_PROGRESS = 'started';

    /** Is this one of the team's messages at all? */
    public static function looksLikeOne(string $text): bool
    {
        return (bool) preg_match('/daily\s*task|eod\s*check/i', $text);
    }

    /**
     * @return array{
     *     date: Carbon,
     *     people: array<string, list<array{client: string, title: string, line: string, status: ?string}>>,
     *     check: ?array{team: string, items: list<array{label: string, ok: bool, note: ?string}>, status: ?string}
     * }
     */
    public static function parse(string $text, ?Carbon $today = null): array
    {
        $date = ($today ?? today())->copy()->startOfDay();
        if (preg_match('/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\b/', $text, $m)) {
            try {
                $date = Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
            } catch (Throwable) {
                // An impossible date: keep today's.
            }
        }

        if (preg_match('/eod\s*check/i', $text)) {
            return ['date' => $date, 'people' => [], 'check' => self::check($text)];
        }

        $people = [];
        $current = null;
        foreach (preg_split('/\R/u', $text) as $raw) {
            $line = trim(str_replace(['*', '_'], '', $raw));
            if ($line === '') {
                continue;
            }

            // A heading: one or two words in capitals ending in a colon -- a
            // person. "Daily Task:" is not shouting, so it is not one.
            if (preg_match('/^([A-Z][A-Z .]{1,30}?)\s*:\s*$/u', $line, $m)) {
                $current = trim($m[1]);
                $people[$current] ??= [];

                continue;
            }

            // A task: "1.SVA - Sharara Kurti Set (Completed)".
            if ($current !== null && preg_match('/^\d+\s*[.)]\s*(.+)$/u', $line, $m)) {
                $people[$current][] = self::task($m[1]);

                continue;
            }

            // Any other line in capitals ends the list ("IG POST BY ...").
            if (preg_match('/^[A-Z0-9 &()\-]{4,}$/u', $line)) {
                $current = null;
            }
        }

        return ['date' => $date, 'people' => array_filter($people), 'check' => null];
    }

    /** @return array{client: string, title: string, line: string, status: ?string} */
    private static function task(string $body): array
    {
        $status = null;
        if (preg_match('/\(([^)]*)\)\s*$/u', $body, $m)) {
            $mark = strtolower($m[1]);
            $status = match (true) {
                str_contains($mark, 'complet') || str_contains($mark, 'done') || str_contains($mark, 'posted') => self::DONE,
                str_contains($mark, 'progress') || str_contains($mark, 'started') => self::IN_PROGRESS,
                default => null,
            };
            if ($status !== null) {
                $body = trim(substr($body, 0, -strlen($m[0])));
            }
        }

        // "SVA Tier 2 - Customer Review": the client, then the work.
        $parts = preg_split('/\s+[-–]\s+/u', $body, 2);
        $client = count($parts) === 2 ? trim($parts[0]) : '';

        return ['client' => $client, 'title' => trim(preg_replace('/\s+/u', ' ', $body)), 'line' => $body, 'status' => $status];
    }

    /** @return array{team: string, items: list<array{label: string, ok: bool, note: ?string}>, status: ?string} */
    private static function check(string $text): array
    {
        $team = preg_match('/^\W*([^|\n]+?)\s*\|\s*eod/imu', $text, $m) ? trim($m[1]) : 'Team';
        $items = [];
        foreach (preg_split('/\R/u', $text) as $raw) {
            $line = trim(str_replace('*', '', $raw));
            if (! preg_match('/^\d+\s*[.)]\s*(.+?)\s*[—–-]+\s*(✅|❌|✔️|✖️|☑️)\s*(.*)$/u', $line, $m)) {
                continue;
            }
            $note = trim($m[3], " ()");
            $items[] = ['label' => trim($m[1]), 'ok' => in_array($m[2], ['✅', '✔️', '☑️'], true), 'note' => $note === '' ? null : $note];
        }
        $status = preg_match('/status\s*:\s*(\w+)/i', $text, $m) ? ucfirst(strtolower($m[1])) : null;

        return ['team' => $team, 'items' => $items, 'status' => $status];
    }
}
