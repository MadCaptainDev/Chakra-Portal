<?php

namespace App\Services;

use App\Models\TimesheetEntry;
use App\Support\TimesheetVenture;
use RuntimeException;
use ZipArchive;

/**
 * Turns the messy Daily Timesheet workbook into a clean one-sheet-per-employee
 * xlsx with normalised dates, times, effort minutes and status — before any
 * database import.
 */
class TimesheetWorkbookCleaner
{
    public function __construct(private TimesheetSheetImporter $importer) {}

    /**
     * @return array{
     *   path: string,
     *   sheets: array<string, array{rows: int, total_minutes: int, date_min: ?string, date_max: ?string, recovered: int, zero_effort: int}>
     * }
     */
    public function clean(string $sourcePath, string $destPath): array
    {
        if (! is_file($sourcePath)) {
            throw new RuntimeException("Source workbook not found: {$sourcePath}");
        }

        $workbook = $this->importer->readWorkbook($sourcePath);
        $cleaned = [];
        $report = [];

        foreach (TimesheetSheetImporter::EMPLOYEES as $sheetName => $meta) {
            $rows = $workbook[$sheetName] ?? [];
            $out = [];
            $lastDate = null;
            $totalMinutes = 0;
            $recovered = 0;
            $zero = 0;
            $dateMin = null;
            $dateMax = null;

            foreach ($rows as $index => $raw) {
                $mapped = $this->cleanRow($raw, $lastDate);
                if ($mapped === null) {
                    continue;
                }

                if ($mapped['worked_on'] !== null) {
                    $lastDate = $mapped['worked_on'];
                }

                if ($mapped['worked_on'] === null || $mapped['task'] === '') {
                    continue;
                }

                if ($mapped['recovered']) {
                    $recovered++;
                }
                if ($mapped['minutes'] === 0) {
                    $zero++;
                }

                $totalMinutes += $mapped['minutes'];
                $date = $mapped['worked_on'];
                if ($dateMin === null || $date < $dateMin) {
                    $dateMin = $date;
                }
                if ($dateMax === null || $date > $dateMax) {
                    $dateMax = $date;
                }

                $out[] = $mapped;
            }

            $cleaned[$sheetName] = $out;
            $report[$sheetName] = [
                'rows' => count($out),
                'total_minutes' => $totalMinutes,
                'date_min' => $dateMin,
                'date_max' => $dateMax,
                'recovered' => $recovered,
                'zero_effort' => $zero,
            ];
        }

        $this->writeWorkbook($destPath, $cleaned);

        return ['path' => $destPath, 'sheets' => $report];
    }

    /**
     * Clean a single source row into a normalised entry.
     *
     * @param  array{A: mixed, B: mixed, C: mixed, D: mixed, E: mixed, F: mixed, G: mixed, H?: mixed}  $raw
     * @return array{
     *   worked_on: string,
     *   task: string,
     *   venture: ?string,
     *   started_at: ?string,
     *   ended_at: ?string,
     *   minutes: int,
     *   effort_label: string,
     *   status: string,
     *   notes: ?string,
     *   recovered: bool
     * }|null
     */
    public function cleanRow(array $raw, ?string $lastDate): ?array
    {
        $task = trim((string) ($raw['B'] ?? ''));
        if ($task === '') {
            return null;
        }

        // Typos / whitespace
        $task = preg_replace('/\s+/', ' ', $task) ?? $task;
        $task = match (true) {
            strcasecmp($task, 'Desgin') === 0 => 'Design',
            strcasecmp($task, 'XL Updat') === 0 => 'XL Update',
            default => $task,
        };

        $venture = trim((string) ($raw['C'] ?? ''));
        $venture = $venture === '' ? null : (preg_replace('/\s+/', ' ', $venture) ?? $venture);
        if ($venture !== null) {
            $venture = trim($venture, " \t-/");
            if ($venture === '') {
                $venture = null;
            }
        }
        $venture = TimesheetVenture::normalize($venture);

        [$workedOn, $effortRaw, $dateNote] = $this->importer->resolveDateAndEffortSource($raw, $lastDate);
        if ($workedOn === null) {
            return null;
        }

        $startedAt = $this->importer->parseTime($raw['D'] ?? null);
        $endedAt = $this->importer->parseTime($raw['E'] ?? null);
        $effort = $this->importer->parseEffort($effortRaw);

        $notesParts = [];
        $recovered = false;
        $minutes = null;

        if ($effort['minutes'] !== null) {
            $minutes = $effort['minutes'];
            if ($effort['note'] !== null) {
                $notesParts[] = $effort['note'];
            }
        } else {
            $derived = $this->importer->deriveMinutesFromClocks($startedAt, $endedAt);
            if ($derived !== null && $derived > 0) {
                $minutes = $derived;
                $recovered = true;
                $notesParts[] = 'Effort derived from start/end';
            } else {
                $minutes = 0;
                $notesParts[] = 'No effort or usable times';
            }
        }

        [$status, $statusNote] = $this->importer->normalizeStatus($raw['F'] ?? null);
        if ($statusNote !== null) {
            $notesParts[] = $statusNote;
        }
        if ($dateNote !== null) {
            $notesParts[] = $dateNote;
        }

        $minutes = max(0, min(1440, (int) $minutes));
        $effortLabel = TimesheetEntry::formatMinutes($minutes);
        if ($effortLabel === '—') {
            $effortLabel = '0 mins';
        }

        $notes = $notesParts === [] ? null : implode(' | ', array_unique($notesParts));

        return [
            'worked_on' => $workedOn,
            'task' => mb_substr($task, 0, 255),
            'venture' => $venture !== null ? mb_substr($venture, 0, 255) : null,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'minutes' => $minutes,
            'effort_label' => $effortLabel,
            'status' => $status,
            'notes' => $notes !== null ? mb_substr($notes, 0, 2000) : null,
            'recovered' => $recovered,
        ];
    }

    /**
     * @return array{0: ?string, 1: mixed, 2: ?string} [date, effortRaw, note]
     */
    public function resolveDateAndEffortSource(array $raw, ?string $lastDate): array
    {
        return $this->importer->resolveDateAndEffortSource($raw, $lastDate);
    }

    public function deriveMinutesFromClocks(?string $start, ?string $end): ?int
    {
        return $this->importer->deriveMinutesFromClocks($start, $end);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sheets
     */
    private function writeWorkbook(string $destPath, array $sheets): void
    {
        $dir = dirname($destPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Build shared string table.
        $shared = [
            'Date',
            'Task Name',
            'Venture / Domain',
            'Start Time',
            'End Time',
            'Status',
            'Effort Hours',
            'Notes',
        ];
        $sharedIndex = array_flip($shared);

        foreach ($sheets as $rows) {
            foreach ($rows as $row) {
                foreach (['worked_on', 'task', 'venture', 'status', 'effort_label', 'notes', 'started_at', 'ended_at'] as $key) {
                    $val = $row[$key] ?? null;
                    if ($val === null || $val === '') {
                        continue;
                    }
                    $s = (string) $val;
                    if (! isset($sharedIndex[$s])) {
                        $sharedIndex[$s] = count($shared);
                        $shared[] = $s;
                    }
                }
            }
        }

        $tmp = sys_get_temp_dir().'/ts_clean_'.uniqid();
        mkdir($tmp.'/xl/worksheets', 0777, true);
        mkdir($tmp.'/xl/_rels', 0777, true);
        mkdir($tmp.'/_rels', 0777, true);

        $siXml = '';
        foreach ($shared as $s) {
            $siXml .= '<si><t>'.htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
        }
        file_put_contents(
            $tmp.'/xl/sharedStrings.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            .count($shared).'" uniqueCount="'.count($shared).'">'.$siXml.'</sst>'
        );

        $sheetNames = array_keys($sheets);
        $workbookSheets = '';
        $rels = '';
        $overrides = '';
        $i = 0;
        foreach ($sheetNames as $name) {
            $i++;
            $workbookSheets .= '<sheet name="'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                .'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';

            $xml = $this->sheetToXml($sheets[$name], $sharedIndex);
            file_put_contents($tmp.'/xl/worksheets/sheet'.$i.'.xml', $xml);
        }
        $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        file_put_contents(
            $tmp.'/xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$workbookSheets.'</sheets></workbook>'
        );
        file_put_contents(
            $tmp.'/xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels.'</Relationships>'
        );
        file_put_contents(
            $tmp.'/_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>'
        );
        file_put_contents(
            $tmp.'/[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .$overrides.'</Types>'
        );

        if (is_file($destPath)) {
            unlink($destPath);
        }

        $zip = new ZipArchive;
        if ($zip->open($destPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Could not create {$destPath}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $local = substr($file->getPathname(), strlen($tmp) + 1);
            $zip->addFile($file->getPathname(), $local);
        }
        $zip->close();

        // Cleanup temp tree.
        $this->rrmdir($tmp);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $sharedIndex
     */
    private function sheetToXml(array $rows, array $sharedIndex): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        // Header
        $headers = ['Date', 'Task Name', 'Venture / Domain', 'Start Time', 'End Time', 'Status', 'Effort Hours', 'Notes'];
        $xml .= '<row r="1">';
        foreach ($headers as $i => $h) {
            $col = chr(ord('A') + $i);
            $xml .= '<c r="'.$col.'1" t="s"><v>'.$sharedIndex[$h].'</v></c>';
        }
        $xml .= '</row>';

        $r = 1;
        foreach ($rows as $row) {
            $r++;
            $xml .= '<row r="'.$r.'">';
            // Date as ISO string (shared) for readability in Excel.
            $xml .= '<c r="A'.$r.'" t="s"><v>'.$this->si($sharedIndex, $row['worked_on']).'</v></c>';
            $xml .= '<c r="B'.$r.'" t="s"><v>'.$this->si($sharedIndex, $row['task']).'</v></c>';
            if (! empty($row['venture'])) {
                $xml .= '<c r="C'.$r.'" t="s"><v>'.$this->si($sharedIndex, $row['venture']).'</v></c>';
            }
            if (! empty($row['started_at'])) {
                $xml .= '<c r="D'.$r.'" t="s"><v>'.$this->si($sharedIndex, $row['started_at']).'</v></c>';
            }
            if (! empty($row['ended_at'])) {
                $xml .= '<c r="E'.$r.'" t="s"><v>'.$this->si($sharedIndex, $row['ended_at']).'</v></c>';
            }
            $xml .= '<c r="F'.$r.'" t="s"><v>'.$this->si($sharedIndex, $row['status']).'</v></c>';
            $xml .= '<c r="G'.$r.'" t="s"><v>'.$this->si($sharedIndex, $row['effort_label']).'</v></c>';
            // Also store numeric minutes in a data attribute? Keep effort label human.
            // Put minutes as a number in a hidden sense — actually put minutes in column I? Stick to plan columns.
            // Store minutes as number by encoding in effort — importer will parse "16 hrs 30 mins".
            if (! empty($row['notes'])) {
                $xml .= '<c r="H'.$r.'" t="s"><v>'.$this->si($sharedIndex, $row['notes']).'</v></c>';
            }
            // Minutes as explicit number in column I for reliable re-import.
            $xml .= '<c r="I'.$r.'"><v>'.(int) $row['minutes'].'</v></c>';
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    /**
     * @param  array<string, int>  $sharedIndex
     */
    private function si(array $sharedIndex, string $value): int
    {
        if (! isset($sharedIndex[$value])) {
            throw new RuntimeException("Missing shared string: {$value}");
        }

        return $sharedIndex[$value];
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
