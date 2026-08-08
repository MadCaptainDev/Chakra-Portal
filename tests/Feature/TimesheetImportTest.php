<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetSheetImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class TimesheetImportTest extends TestCase
{
    use RefreshDatabase;

    private TimesheetSheetImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new TimesheetSheetImporter;
    }

    public function test_effort_text_is_parsed_the_way_the_team_writes_it(): void
    {
        $this->assertSame(990, $this->importer->parseEffort('16 hrs 30 mins')['minutes']);
        $this->assertSame(240, $this->importer->parseEffort('4 hrs')['minutes']);
        $this->assertSame(60, $this->importer->parseEffort('1hr')['minutes']);
        $this->assertSame(30, $this->importer->parseEffort('30Mins')['minutes']);
        $this->assertSame(90, $this->importer->parseEffort('1hr 30mins')['minutes']);
        $this->assertSame(360, $this->importer->parseEffort('6 hrs(Because of technical Issue)')['minutes']);
        $this->assertSame('Because of technical Issue', $this->importer->parseEffort('6 hrs(Because of technical Issue)')['note']);
    }

    public function test_numeric_effort_rejects_date_serials_and_negatives(): void
    {
        $this->assertNull($this->importer->parseEffort(46114)['minutes']);
        $this->assertSame(360, $this->importer->parseEffort(-6)['minutes']);
        $this->assertSame(231, $this->importer->parseEffort(3.85)['minutes']);
        $this->assertSame(420, $this->importer->parseEffort(7)['minutes']);
    }

    public function test_status_typos_and_notes_are_normalised(): void
    {
        $this->assertSame('completed', $this->importer->normalizeStatus('Completed ')[0]);
        $this->assertSame('cancelled', $this->importer->normalizeStatus('Cacel')[0]);
        $this->assertSame('pending', $this->importer->normalizeStatus('Pending')[0]);
        $this->assertSame('completed', $this->importer->normalizeStatus('Davinci issue')[0]);
        $this->assertStringContainsString('Davinci', (string) $this->importer->normalizeStatus('Davinci issue')[1]);
    }

    public function test_excel_dates_and_mixed_clock_formats_parse(): void
    {
        $this->assertSame('2026-04-02', $this->importer->parseDate(46114));
        $this->assertSame('09:00', $this->importer->parseTime(0.375));
        $this->assertSame('23:00', $this->importer->parseTime('11.00pm'));
        $this->assertSame('08:27', $this->importer->parseTime(8.45));
        $this->assertSame('10:18', $this->importer->parseTime(10.3));
    }

    public function test_map_row_prefers_effort_hours_over_clock_span(): void
    {
        // Same trap as the live Aron sheet: clocks span 4.5h, effort says 16h30.
        $mapped = $this->importer->mapRow([
            'A' => 46119,
            'B' => 'Shoot',
            'C' => 'PR',
            'D' => 0.25,
            'E' => 0.4375,
            'F' => 'Completed',
            'G' => '16 hrs 30 mins',
            'H' => '',
        ], null);

        $this->assertSame('2026-04-07', $mapped['worked_on']);
        $this->assertSame(990, $mapped['minutes']);
        $this->assertFalse($mapped['recovered']);
    }

    public function test_map_row_uses_gokul_date_from_column_g_and_shortens_false_overnights(): void
    {
        $mapped = $this->importer->mapRow([
            'A' => '',
            'B' => 'Editing',
            'C' => 'SVA / RED-SAREE VIDEO',
            'D' => 0.4791666666666667, // 11:30
            'E' => 0.041666666666666664, // 01:00 — meant 13:00
            'F' => 'completed',
            'G' => 46115, // real date lives here on Gokul's sheet
            'H' => '',
        ], null);

        $this->assertSame('2026-04-03', $mapped['worked_on']);
        $this->assertTrue($mapped['recovered']);
        $this->assertSame(90, $mapped['minutes']); // 11:30 → 13:00, not 13.5h overnight
    }

    public function test_derive_minutes_keeps_real_short_overnights(): void
    {
        $this->assertSame(90, $this->importer->deriveMinutesFromClocks('23:00', '00:30'));
        $this->assertSame(365, $this->importer->deriveMinutesFromClocks('10:40', '04:45'));
    }

    public function test_import_creates_employees_links_salaries_and_writes_entries(): void
    {
        foreach (['Aron', 'Sanjai', 'Gokul', 'Nitis'] as $name) {
            Expense::create([
                'name' => $name,
                'type' => Expense::TYPE_SALARY,
                'amount' => 1000,
                'is_active' => true,
            ]);
        }

        $path = $this->makeFixtureXlsx();

        $exit = Artisan::call('timesheet:import', [
            'file' => $path,
            '--fresh' => true,
        ]);

        $this->assertSame(0, $exit);

        foreach (TimesheetSheetImporter::EMPLOYEES as $meta) {
            $user = User::where('email', $meta['email'])->first();
            $this->assertNotNull($user);
            $this->assertSame(User::ROLE_EMPLOYEE, $user->role);
            $this->assertTrue(Hash::check(TimesheetSheetImporter::DEFAULT_PASSWORD, $user->password));
            $this->assertNotNull(
                Expense::where('type', Expense::TYPE_SALARY)
                    ->where('name', $meta['name'])
                    ->where('user_id', $user->id)
                    ->first()
            );
        }

        $aron = User::where('email', 'aron@chakragroups.in')->firstOrFail();
        $this->assertDatabaseHas('timesheet_entries', [
            'user_id' => $aron->id,
            'task' => 'Photo Upload',
            'minutes' => 240,
            'status' => 'completed',
        ]);

        // Effort wins over the short clock span.
        $this->assertDatabaseHas('timesheet_entries', [
            'user_id' => $aron->id,
            'task' => 'Long Shoot',
            'minutes' => 990,
        ]);

        $gokul = User::where('email', 'gokul@chakragroups.in')->firstOrFail();
        $gokulEntry = TimesheetEntry::where('user_id', $gokul->id)->firstOrFail();
        $this->assertSame(120, $gokulEntry->minutes);
        $this->assertSame('2026-04-02', $gokulEntry->worked_on->toDateString());

        // --fresh reimport does not duplicate (2 Aron + 2 Gokul).
        Artisan::call('timesheet:import', ['file' => $path, '--fresh' => true]);
        $this->assertSame(4, TimesheetEntry::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $path = $this->makeFixtureXlsx();

        Artisan::call('timesheet:import', [
            'file' => $path,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, User::where('role', User::ROLE_EMPLOYEE)->count());
        $this->assertSame(0, TimesheetEntry::count());
    }

    /**
     * Minimal workbook covering Aron (effort text) + Gokul (date-in-effort, blank date).
     */
    private function makeFixtureXlsx(): string
    {
        $dir = sys_get_temp_dir().'/ts_fixture_'.uniqid();
        mkdir($dir.'/xl/worksheets', 0777, true);
        mkdir($dir.'/xl/_rels', 0777, true);
        mkdir($dir.'/_rels', 0777, true);

        $shared = [
            'Date', 'Task Name', 'Venture / Domain', 'Start Time', 'End Time', 'Status', 'Effort Hours',
            'Photo Upload', 'SVA Website', 'Completed', '4 hrs',
            'Long Shoot', 'PR', '16 hrs 30 mins',
            'Editing', 'SVA / RED-SAREE VIDEO', 'completed',
        ];

        $si = '';
        foreach ($shared as $s) {
            $si .= '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>';
        }

        file_put_contents($dir.'/xl/sharedStrings.xml',
            '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($shared).'" uniqueCount="'.count($shared).'">'.$si.'</sst>');

        file_put_contents($dir.'/xl/workbook.xml',
            '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            .'<sheet name="Aron" sheetId="1" r:id="rId1"/>'
            .'<sheet name="Sanjai" sheetId="2" r:id="rId2"/>'
            .'<sheet name="Gokul" sheetId="3" r:id="rId3"/>'
            .'<sheet name="Nitis" sheetId="4" r:id="rId4"/>'
            .'</sheets></workbook>');

        file_put_contents($dir.'/xl/_rels/workbook.xml.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
            .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>'
            .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>');

        file_put_contents($dir.'/_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        // Aron: two real rows.
        file_put_contents($dir.'/xl/worksheets/sheet1.xml', $this->sheetXml([
            [0, 1, 2, 3, 4, 5, 6], // header shared indexes
            // date serial, Photo Upload, SVA Website, 01:00, 05:00, Completed, 4 hrs
            ['n' => 46114, 's' => 7, 's2' => 8, 'n2' => 0.041666666666666664, 'n3' => 0.20833333333333334, 's3' => 9, 's4' => 10],
            // Long Shoot / PR / 06:00-10:30 / 16 hrs 30 mins
            ['n' => 46119, 's' => 11, 's2' => 12, 'n2' => 0.25, 'n3' => 0.4375, 's3' => 9, 's4' => 13],
        ], true));

        // Empty Sanjai / Nitis
        file_put_contents($dir.'/xl/worksheets/sheet2.xml', $this->sheetXml([[0, 1, 2, 3, 4, 5, 6]], true));
        file_put_contents($dir.'/xl/worksheets/sheet4.xml', $this->sheetXml([[0, 1, 2, 3, 4, 5, 6]], true));

        // Gokul: blank date (forward-fill from prior), date serial in effort, 02:00-04:00.
        file_put_contents($dir.'/xl/worksheets/sheet3.xml', $this->sheetXml([
            [0, 1, 2, 3, 4, 5, 6],
            // first row establishes date
            ['n' => 46114, 's' => 14, 's2' => 15, 'n2' => 0.08333333333333333, 'n3' => 0.16666666666666666, 's3' => 16, 'n4' => 46114],
            // second row blank date, effort is date serial
            ['s' => 14, 's2' => 15, 'n2' => 0.08333333333333333, 'n3' => 0.16666666666666666, 's3' => 16, 'n4' => 46115],
        ], true));

        file_put_contents($dir.'/[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>');

        $xlsx = $dir.'.xlsx';
        $zip = new ZipArchive;
        $zip->open($xlsx, ZipArchive::CREATE);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $local = substr($file->getPathname(), strlen($dir) + 1);
            $zip->addFile($file->getPathname(), $local);
        }
        $zip->close();

        return $xlsx;
    }

    /**
     * @param  list<mixed>  $rows
     */
    private function sheetXml(array $rows, bool $firstIsHeaderIndexes): string
    {
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $i => $row) {
            $r = $i + 1;
            $xml .= '<row r="'.$r.'">';
            if ($i === 0 && $firstIsHeaderIndexes) {
                foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $ci => $col) {
                    $xml .= '<c r="'.$col.$r.'" t="s"><v>'.$row[$ci].'</v></c>';
                }
            } else {
                $map = [
                    'A' => $row['n'] ?? null,
                    'B' => isset($row['s']) ? ['s', $row['s']] : null,
                    'C' => isset($row['s2']) ? ['s', $row['s2']] : null,
                    'D' => $row['n2'] ?? null,
                    'E' => $row['n3'] ?? null,
                    'F' => isset($row['s3']) ? ['s', $row['s3']] : null,
                    'G' => isset($row['s4']) ? ['s', $row['s4']] : ($row['n4'] ?? null),
                ];
                foreach ($map as $col => $val) {
                    if ($val === null) {
                        continue;
                    }
                    if (is_array($val)) {
                        $xml .= '<c r="'.$col.$r.'" t="s"><v>'.$val[1].'</v></c>';
                    } else {
                        $xml .= '<c r="'.$col.$r.'"><v>'.$val.'</v></c>';
                    }
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        return $xml;
    }
}
