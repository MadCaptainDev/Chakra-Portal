<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TimesheetVenture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Imports Daily Timesheet .xlsx into employee logins + timesheet_entries.
 *
 * Effort Hours is preferred when it parses cleanly. Clock times are a fallback
 * because the old sheet often used 12-hour times with no AM/PM.
 */
class TimesheetSheetImporter
{
    public const DEFAULT_PASSWORD = 'Chakra@2026';

    /** @var array<string, array{email: string, name: string}> */
    public const EMPLOYEES = [
        'Aron' => ['email' => 'aron@chakragroups.in', 'name' => 'Aron'],
        'Sanjai' => ['email' => 'sanjai@chakragroups.in', 'name' => 'Sanjai'],
        'Gokul' => ['email' => 'gokul@chakragroups.in', 'name' => 'Gokul'],
        'Nitis' => ['email' => 'nitis@chakragroups.in', 'name' => 'Nitis'],
    ];

    /**
     * @return array{
     *   dry_run: bool,
     *   users: array<int, array{name: string, email: string, id: int|null}>,
     *   sheets: array<string, array{
     *     imported: int,
     *     skipped: int,
     *     recovered_minutes: int,
     *     total_minutes: int,
     *     pending: int,
     *     cancelled: int,
     *     date_min: ?string,
     *     date_max: ?string,
     *     issues: list<string>
     *   }>
     * }
     */
    public function run(string $path, bool $fresh = false, bool $dryRun = false): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Timesheet file not found: {$path}");
        }

        if ($dryRun) {
            return $this->preview($path);
        }

        $workbook = $this->readWorkbook($path);

        return DB::transaction(function () use ($workbook, $fresh) {
            $report = [
                'dry_run' => false,
                'users' => [],
                'sheets' => [],
            ];
            $usersBySheet = [];

            foreach (self::EMPLOYEES as $sheetName => $meta) {
                $user = $this->provisionUser($meta['name'], $meta['email']);
                $usersBySheet[$sheetName] = $user;
                $report['users'][] = [
                    'name' => $meta['name'],
                    'email' => $meta['email'],
                    'id' => $user->id,
                ];
            }

            if ($fresh) {
                $ids = collect($usersBySheet)->pluck('id')->all();
                TimesheetEntry::whereIn('user_id', $ids)->delete();
            }

            foreach (self::EMPLOYEES as $sheetName => $meta) {
                $report['sheets'][$sheetName] = $this->importSheet(
                    $sheetName,
                    $workbook[$sheetName] ?? [],
                    $usersBySheet[$sheetName],
                    false
                );
            }

            return $report;
        });
    }

    /**
     * Parse + summarise without touching the database.
     *
     * @return array<string, mixed>
     */
    public function preview(string $path): array
    {
        $workbook = $this->readWorkbook($path);
        $report = [
            'dry_run' => true,
            'users' => [],
            'sheets' => [],
        ];

        foreach (self::EMPLOYEES as $sheetName => $meta) {
            $report['users'][] = [
                'name' => $meta['name'],
                'email' => $meta['email'],
                'id' => null,
            ];
            $report['sheets'][$sheetName] = $this->importSheet(
                $sheetName,
                $workbook[$sheetName] ?? [],
                null,
                true
            );
        }

        return $report;
    }

    /**
     * @param  list<array{A: mixed, B: mixed, C: mixed, D: mixed, E: mixed, F: mixed, G: mixed, H?: mixed}>  $rows
     * @return array{
     *   imported: int,
     *   skipped: int,
     *   recovered_minutes: int,
     *   total_minutes: int,
     *   pending: int,
     *   cancelled: int,
     *   date_min: ?string,
     *   date_max: ?string,
     *   issues: list<string>
     * }
     */
    public function importSheet(string $sheetName, array $rows, ?User $user, bool $dryRun): array
    {
        $imported = 0;
        $skipped = 0;
        $recovered = 0;
        $totalMinutes = 0;
        $pending = 0;
        $cancelled = 0;
        $dateMin = null;
        $dateMax = null;
        $issues = [];
        $lastDate = null;

        foreach ($rows as $index => $raw) {
            $rowNumber = $index + 2; // header is row 1
            $mapped = $this->mapRow($raw, $lastDate, $sheetName);

            if ($mapped === null) {
                $skipped++;

                continue;
            }

            if ($mapped['worked_on'] !== null) {
                $lastDate = $mapped['worked_on'];
            }

            if ($mapped['worked_on'] === null) {
                $skipped++;
                $issues[] = "Row {$rowNumber}: skipped — no date";

                continue;
            }

            if ($mapped['task'] === '') {
                $skipped++;

                continue;
            }

            if ($mapped['recovered']) {
                $recovered++;
                if (count($issues) < 40) {
                    $issues[] = "Row {$rowNumber}: minutes recovered from clock ({$mapped['minutes']} mins)".
                        ($mapped['issue'] ? " — {$mapped['issue']}" : '');
                }
            } elseif ($mapped['issue'] && count($issues) < 40) {
                $issues[] = "Row {$rowNumber}: {$mapped['issue']}";
            }

            $totalMinutes += $mapped['minutes'];
            if ($mapped['status'] === TimesheetEntry::STATUS_PENDING) {
                $pending++;
            }
            if ($mapped['status'] === TimesheetEntry::STATUS_CANCELLED) {
                $cancelled++;
            }

            $date = $mapped['worked_on'];
            if ($dateMin === null || $date < $dateMin) {
                $dateMin = $date;
            }
            if ($dateMax === null || $date > $dateMax) {
                $dateMax = $date;
            }

            if (! $dryRun && $user) {
                TimesheetEntry::create([
                    'user_id' => $user->id,
                    'worked_on' => $mapped['worked_on'],
                    'task' => $mapped['task'],
                    'task_type' => TimesheetEntry::inferTaskType($mapped['task']),
                    'venture' => $mapped['venture'],
                    'started_at' => $mapped['started_at'],
                    'ended_at' => $mapped['ended_at'],
                    'minutes' => $mapped['minutes'],
                    'status' => $mapped['status'],
                    'notes' => $mapped['notes'],
                ]);
            }

            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'recovered_minutes' => $recovered,
            'total_minutes' => $totalMinutes,
            'pending' => $pending,
            'cancelled' => $cancelled,
            'date_min' => $dateMin,
            'date_max' => $dateMax,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array{A: mixed, B: mixed, C: mixed, D: mixed, E: mixed, F: mixed, G: mixed, H?: mixed}  $raw
     * @return array{
     *   worked_on: ?string,
     *   task: string,
     *   venture: ?string,
     *   started_at: ?string,
     *   ended_at: ?string,
     *   minutes: int,
     *   status: string,
     *   notes: ?string,
     *   recovered: bool,
     *   issue: ?string
     * }|null
     */
    public function mapRow(array $raw, ?string $lastDate, string $sheetName = ''): ?array
    {
        $task = trim((string) ($raw['B'] ?? ''));
        if ($task === '') {
            return null;
        }

        // Clean workbook rows carry an explicit minutes column (I).
        if (isset($raw['I']) && $raw['I'] !== '' && is_numeric($raw['I'])) {
            $workedOn = $this->parseDate($raw['A'] ?? null) ?? $lastDate;
            if ($workedOn === null) {
                return null;
            }

            [$status] = $this->normalizeStatus($raw['F'] ?? 'completed');
            $startedAt = $this->parseTime($raw['D'] ?? null);
            if (! $startedAt && is_string($raw['D'] ?? null) && preg_match('/^\d{2}:\d{2}/', (string) $raw['D'])) {
                $startedAt = substr((string) $raw['D'], 0, 5);
            }
            $endedAt = $this->parseTime($raw['E'] ?? null);
            if (! $endedAt && is_string($raw['E'] ?? null) && preg_match('/^\d{2}:\d{2}/', (string) $raw['E'])) {
                $endedAt = substr((string) $raw['E'], 0, 5);
            }

            $notes = $this->nullableTrim($raw['H'] ?? null);
            $venture = TimesheetVenture::normalize($this->nullableTrim($raw['C'] ?? null));

            return [
                'worked_on' => $workedOn,
                'task' => mb_substr(preg_replace('/\s+/', ' ', $task) ?? $task, 0, 255),
                'venture' => $venture !== null ? mb_substr($venture, 0, 255) : null,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'minutes' => max(0, min(1440, (int) $raw['I'])),
                'status' => $status,
                'notes' => $notes !== null ? mb_substr($notes, 0, 2000) : null,
                'recovered' => false,
                'issue' => null,
            ];
        }

        // Dirty source rows — same rules as TimesheetWorkbookCleaner::cleanRow.
        $task = preg_replace('/\s+/', ' ', $task) ?? $task;
        $task = match (true) {
            strcasecmp($task, 'Desgin') === 0 => 'Design',
            strcasecmp($task, 'XL Updat') === 0 => 'XL Update',
            default => $task,
        };

        $venture = $this->nullableTrim($raw['C'] ?? null);
        if ($venture !== null) {
            $venture = preg_replace('/\s+/', ' ', $venture) ?? $venture;
            $venture = trim($venture, " \t-/");
            if ($venture === '') {
                $venture = null;
            }
        }
        $venture = TimesheetVenture::normalize($venture);

        [$workedOn, $effortRaw, $dateNote] = $this->resolveDateAndEffortSource($raw, $lastDate);
        if ($workedOn === null) {
            return null;
        }

        $startedAt = $this->parseTime($raw['D'] ?? null);
        $endedAt = $this->parseTime($raw['E'] ?? null);
        $effort = $this->parseEffort($effortRaw);
        $notesParts = [];
        $recovered = false;
        $minutes = null;
        $issue = null;

        if ($effort['minutes'] !== null) {
            $minutes = $effort['minutes'];
            if ($effort['note'] !== null) {
                $notesParts[] = $effort['note'];
            }
        } else {
            if ($effort['reason'] !== null) {
                $issue = $effort['reason'];
            }
            $derived = $this->deriveMinutesFromClocks($startedAt, $endedAt);
            if ($derived !== null && $derived > 0) {
                $minutes = $derived;
                $recovered = true;
                $notesParts[] = 'Effort derived from start/end';
            } else {
                $minutes = 0;
                $issue = ($issue ? $issue.'; ' : '').'could not derive duration';
                $notesParts[] = 'No effort or usable times';
            }
        }

        [$status, $statusNote] = $this->normalizeStatus($raw['F'] ?? null);
        if ($statusNote !== null) {
            $notesParts[] = $statusNote;
        }
        if ($dateNote !== null) {
            $notesParts[] = $dateNote;
        }

        $notes = $notesParts === [] ? null : implode(' | ', array_unique($notesParts));

        return [
            'worked_on' => $workedOn,
            'task' => mb_substr($task, 0, 255),
            'venture' => $venture !== null ? mb_substr($venture, 0, 255) : null,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'minutes' => max(0, min(1440, (int) $minutes)),
            'status' => $status,
            'notes' => $notes !== null ? mb_substr($notes, 0, 2000) : null,
            'recovered' => $recovered,
            'issue' => $issue,
        ];
    }

    /**
     * Gokul parked the date in column G. Nitis often parks it in H while G
     * holds effort. Aron/Sanjai keep the date in A and effort in G.
     *
     * @return array{0: ?string, 1: mixed, 2: ?string} [date, effortRaw, note]
     */
    public function resolveDateAndEffortSource(array $raw, ?string $lastDate): array
    {
        $aDate = $this->parseDate($raw['A'] ?? null);
        $gDate = $this->isDateSerial($raw['G'] ?? null) ? $this->parseDate($raw['G']) : null;
        $hDate = $this->isDateSerial($raw['H'] ?? null) ? $this->parseDate($raw['H']) : null;

        if ($hDate !== null) {
            return [$hDate, $raw['G'] ?? null, null];
        }

        if ($aDate !== null && $gDate === null) {
            return [$aDate, $raw['G'] ?? null, null];
        }

        if ($aDate !== null && $gDate !== null) {
            return [$aDate, null, null];
        }

        if ($gDate !== null) {
            return [$gDate, null, null];
        }

        if ($aDate !== null) {
            return [$aDate, $raw['G'] ?? null, null];
        }

        if ($lastDate !== null) {
            $effort = $this->isDateSerial($raw['G'] ?? null) ? null : ($raw['G'] ?? null);

            return [$lastDate, $effort, 'Date forward-filled'];
        }

        return [null, null, 'Missing date'];
    }

    /**
     * When Effort Hours is missing, derive duration from clocks.
     *
     * Apparent overnight spans longer than 12 hours usually mean the end was
     * typed as AM but meant PM (01:00 → 13:00).
     */
    public function deriveMinutesFromClocks(?string $start, ?string $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }

        $overnight = TimesheetEntry::minutesBetween($start, $end);
        if ($overnight === null || $overnight <= 0) {
            return null;
        }

        [$sh, $sm] = array_map('intval', explode(':', substr($start, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr($end, 0, 5)));
        $startMins = $sh * 60 + $sm;
        $endMins = $eh * 60 + $em;

        if ($endMins < $startMins && $overnight > 12 * 60) {
            return $overnight - (12 * 60);
        }

        return $overnight;
    }

    private function isDateSerial(mixed $value): bool
    {
        if (! is_numeric($value)) {
            return false;
        }
        $n = (float) $value;

        return $n > 30000 && $n < 60000;
    }

    /**
     * @return array{minutes: ?int, note: ?string, reason: ?string}
     */
    public function parseEffort(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['minutes' => null, 'note' => null, 'reason' => 'empty effort'];
        }

        if (is_numeric($value)) {
            $n = (float) $value;

            // Excel date serial dumped into Effort Hours (Gokul / some Nitis rows).
            if ($n > 30000) {
                return ['minutes' => null, 'note' => null, 'reason' => 'effort held a date serial'];
            }

            if ($n < 0) {
                // Sheet formulas that assume end ≥ start produce negatives like -6
                // when the session crossed noon/midnight. The absolute value is
                // usually the intended decimal hours.
                $abs = abs($n);
                if ($abs > 0 && $abs <= 24) {
                    return [
                        'minutes' => (int) round($abs * 60),
                        'note' => 'Recovered from negative effort formula',
                        'reason' => null,
                    ];
                }

                return ['minutes' => null, 'note' => null, 'reason' => 'negative effort'];
            }

            // Decimal hours, e.g. 4.3 → 4h 18m.
            if ($n <= 24) {
                return ['minutes' => (int) round($n * 60), 'note' => null, 'reason' => null];
            }

            return ['minutes' => null, 'note' => null, 'reason' => 'unusable numeric effort'];
        }

        $raw = trim((string) $value);
        if ($raw === '' || $raw === '—' || $raw === '-') {
            return ['minutes' => null, 'note' => null, 'reason' => 'empty effort'];
        }

        // Status text accidentally in Effort.
        if (preg_match('/^(completed|pending|cancelled)$/i', $raw)) {
            return ['minutes' => null, 'note' => null, 'reason' => 'status text in effort'];
        }

        $note = null;
        if (preg_match('/\((.+)\)/', $raw, $m)) {
            $note = trim($m[1]);
        }

        $main = strtolower(preg_replace('/\s+/', ' ', preg_replace('/\(.*?\)/', '', $raw) ?? $raw) ?? $raw);
        $main = trim($main);

        $minutes = 0;
        $matched = false;

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|h)\b/', $main, $m)) {
            $minutes += (int) round((float) $m[1] * 60);
            $matched = true;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:minutes?|mins?|m)\b/', $main, $m)) {
            $minutes += (int) round((float) $m[1]);
            $matched = true;
        }

        // Compact forms already covered by hrs?/mins? with optional space: "1hr", "30Mins".
        if (! $matched && preg_match('/^(\d+(?:\.\d+)?)$/', $main, $m)) {
            $n = (float) $m[1];
            if ($n > 0 && $n <= 24) {
                return ['minutes' => (int) round($n * 60), 'note' => $note, 'reason' => null];
            }
        }

        if (! $matched) {
            return ['minutes' => null, 'note' => $note, 'reason' => 'unparsed effort "'.$raw.'"'];
        }

        return ['minutes' => $minutes, 'note' => $note, 'reason' => null];
    }

    /**
     * @return array{0: string, 1: ?string}  [status, note]
     */
    public function normalizeStatus(mixed $value): array
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return [TimesheetEntry::STATUS_COMPLETED, null];
        }

        $lower = strtolower(preg_replace('/\s+/', ' ', $raw) ?? $raw);

        if (in_array($lower, ['completed', 'complete', 'done', 'posted'], true)
            || str_starts_with($lower, 'completed')) {
            return [TimesheetEntry::STATUS_COMPLETED, null];
        }

        if (in_array($lower, ['pending', 'export pending'], true)
            || str_starts_with($lower, 'pending')
            || str_contains($lower, 'pending')) {
            $note = in_array($lower, ['pending'], true) ? null : $raw;

            return [TimesheetEntry::STATUS_PENDING, $note];
        }

        if (in_array($lower, ['cancelled', 'canceled', 'cancel', 'cacel'], true)
            || str_starts_with($lower, 'cancel')) {
            return [TimesheetEntry::STATUS_CANCELLED, $lower === 'cacel' ? 'Status typo: Cacel' : null];
        }

        if (in_array($lower, ['no', 'break'], true)) {
            return [TimesheetEntry::STATUS_COMPLETED, 'Original status: '.$raw];
        }

        // Notes that landed in the Status column (Gokul).
        return [TimesheetEntry::STATUS_COMPLETED, 'Status note: '.$raw];
    }

    public function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n < 30000 || $n > 60000) {
                return null;
            }

            // Excel serial date (Windows 1900 system).
            return Carbon::create(1899, 12, 30)->addDays((int) round($n))->toDateString();
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        // Reject ranges like "Aug - 3 to 7".
        if (preg_match('/\bto\b/i', $raw) || preg_match('/^\D+$/', $raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Accept Excel time fractions, decimal clock hours (10.3 → 10:18),
     * and text like "11.00pm" / "11:00 PM".
     */
    public function parseTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $n = (float) $value;

            // Excel date serial mistaken for a time.
            if ($n > 30000) {
                return null;
            }

            // Pure Excel time fraction (0 ≤ n < 1).
            if ($n >= 0 && $n < 1) {
                $totalMins = (int) round($n * 24 * 60);
                $totalMins = (($totalMins % 1440) + 1440) % 1440;
                $h = intdiv($totalMins, 60);
                $m = $totalMins % 60;

                return sprintf('%02d:%02d', $h, $m);
            }

            // Decimal clock: 8.45 → 8h + 0.45×60 ≈ 08:27; 10.3 → 10:18.
            // Also whole hours 7 / 11.
            if ($n >= 0 && $n < 24) {
                $h = (int) floor($n);
                $m = (int) round(($n - $h) * 60);
                if ($m === 60) {
                    $h++;
                    $m = 0;
                }
                if ($h >= 24) {
                    return null;
                }

                return sprintf('%02d:%02d', $h, $m);
            }

            return null;
        }

        $raw = strtolower(trim((string) $value));
        $raw = str_replace([' ', '.'], ['', ':'], $raw);

        if (preg_match('/^(\d{1,2}):(\d{2})(am|pm)?$/', $raw, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $ampm = $m[3] ?? '';
            if ($ampm === 'pm' && $h < 12) {
                $h += 12;
            }
            if ($ampm === 'am' && $h === 12) {
                $h = 0;
            }
            if ($h > 23 || $min > 59) {
                return null;
            }

            return sprintf('%02d:%02d', $h, $min);
        }

        if (preg_match('/^(\d{1,2})(am|pm)$/', $raw, $m)) {
            $h = (int) $m[1];
            if ($m[2] === 'pm' && $h < 12) {
                $h += 12;
            }
            if ($m[2] === 'am' && $h === 12) {
                $h = 0;
            }

            return sprintf('%02d:%02d', $h, 0);
        }

        return null;
    }

    private function provisionUser(string $name, string $email): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::DEFAULT_PASSWORD,
                'role' => User::ROLE_EMPLOYEE,
            ]
        );

        Expense::where('type', Expense::TYPE_SALARY)
            ->where('name', $name)
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->update(['user_id' => $user->id]);

        return $user;
    }

    /**
     * @return array<string, list<array{A: mixed, B: mixed, C: mixed, D: mixed, E: mixed, F: mixed, G: mixed, H: mixed}>>
     */
    public function readWorkbook(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Could not open xlsx: {$path}");
        }

        try {
            $shared = $this->parseSharedStrings($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($workbookXml === false || $relsXml === false) {
                throw new RuntimeException('Invalid xlsx: missing workbook.xml');
            }

            $sheetTargets = $this->sheetTargets($workbookXml, $relsXml);
            $out = [];

            foreach ($sheetTargets as $name => $target) {
                if (! isset(self::EMPLOYEES[$name])) {
                    continue;
                }
                $xml = $zip->getFromName($target);
                if ($xml === false) {
                    $out[$name] = [];

                    continue;
                }
                $out[$name] = $this->parseSheetRows($xml, $shared);
            }

            return $out;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, string> sheet name => zip path
     */
    private function sheetTargets(string $workbookXml, string $relsXml): array
    {
        $ridToTarget = [];
        if (preg_match_all('/Id="(rId\d+)"[^>]*Target="([^"]+)"/', $relsXml, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $ridToTarget[$row[1]] = $row[2];
            }
        }
        if (preg_match_all('/Target="([^"]+)"[^>]*Id="(rId\d+)"/', $relsXml, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $ridToTarget[$row[2]] = $row[1];
            }
        }

        $sheets = [];
        if (preg_match_all('/<sheet[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"/', $workbookXml, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $name = html_entity_decode($row[1], ENT_QUOTES | ENT_XML1);
                if (str_starts_with($name, '_xlnm')) {
                    continue;
                }
                $target = $ridToTarget[$row[2]] ?? null;
                if (! $target) {
                    continue;
                }
                $target = ltrim($target, '/');
                if (! str_starts_with($target, 'xl/')) {
                    $target = 'xl/'.$target;
                }
                $sheets[$name] = $target;
            }
        }

        return $sheets;
    }

    /**
     * @return list<string>
     */
    private function parseSharedStrings(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $strings = [];
        if (preg_match_all('/<si>(.*?)<\/si>/s', $xml, $sis)) {
            foreach ($sis[1] as $si) {
                $text = '';
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $ts)) {
                    foreach ($ts[1] as $t) {
                        $text .= html_entity_decode($t, ENT_QUOTES | ENT_XML1);
                    }
                }
                $strings[] = $text;
            }
        }

        return $strings;
    }

    /**
     * @param  list<string>  $shared
     * @return list<array{A: mixed, B: mixed, C: mixed, D: mixed, E: mixed, F: mixed, G: mixed, H: mixed}>
     */
    private function parseSheetRows(string $xml, array $shared): array
    {
        $byRow = [];

        if (preg_match_all('/<c r="([A-Z]+)(\d+)"([^>]*)>(?:.*?<v>(.*?)<\/v>)?/s', $xml, $m, PREG_SET_ORDER)) {
            foreach ($m as $cell) {
                $col = $cell[1];
                $row = (int) $cell[2];
                if ($row === 1 || $col > 'I') {
                    continue;
                }
                $attrs = $cell[3];
                $v = $cell[4] ?? '';
                $value = $v;
                if (str_contains($attrs, 't="s"')) {
                    $value = $shared[(int) $v] ?? '';
                } elseif ($v !== '' && is_numeric($v)) {
                    $value = str_contains($v, '.') ? (float) $v : (0 + $v);
                }
                $byRow[$row][$col] = $value;
            }
        }

        ksort($byRow, SORT_NUMERIC);
        $rows = [];
        foreach ($byRow as $cols) {
            $rows[] = [
                'A' => $cols['A'] ?? '',
                'B' => $cols['B'] ?? '',
                'C' => $cols['C'] ?? '',
                'D' => $cols['D'] ?? '',
                'E' => $cols['E'] ?? '',
                'F' => $cols['F'] ?? '',
                'G' => $cols['G'] ?? '',
                'H' => $cols['H'] ?? '',
                'I' => $cols['I'] ?? '',
            ];
        }

        return $rows;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim((string) $value);

        return $t === '' ? null : $t;
    }

    private function stringifyCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }
}
