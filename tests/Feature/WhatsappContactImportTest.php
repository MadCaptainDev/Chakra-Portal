<?php

namespace Tests\Feature;

use App\Models\WhatsappContact;
use App\Models\WhatsappPhonebook;
use App\Services\WhatsappContactImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappContactImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wa_import_test_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_valid_rows_are_imported_and_attached_to_the_phonebook(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);

        $csv = $this->csv(
            "Phone,Name,Var1,Var2\n"
            ."7094126823,Ravi,Friday,4pm\n"
            ."+91 98765 43210,Priya,Monday,10am\n"
        );

        $result = (new WhatsappContactImporter)->import($csv, $phonebook);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(2, $phonebook->fresh()->contactsCount());

        $ravi = WhatsappContact::where('phone', '917094126823')->sole();
        $this->assertSame('Ravi', $ravi->name);
        $this->assertSame('Friday', $ravi->var1);
        $this->assertSame('4pm', $ravi->var2);
        $this->assertTrue($ravi->phonebooks->pluck('id')->contains($phonebook->id));
    }

    /**
     * Recognised columns are matched by name, case-insensitively, wherever
     * they sit in the header -- an importer that only worked with one exact
     * column order would fail the first CSV exported from a spreadsheet
     * someone reordered by hand.
     */
    public function test_columns_are_recognised_case_insensitively_and_out_of_order(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);

        $csv = $this->csv(
            "NAME,PHONE,Notes\n"
            ."Ravi,7094126823,ignored column\n"
        );

        $result = (new WhatsappContactImporter)->import($csv, $phonebook);

        $this->assertSame(1, $result['imported']);
        $this->assertSame('Ravi', WhatsappContact::sole()->name);
    }

    public function test_rows_with_an_unparseable_phone_are_skipped_and_counted(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);

        $csv = $this->csv(
            "Phone,Name\n"
            ."7094126823,Ravi\n"
            ."not-a-number,Broken\n"
            .",Missing\n"
        );

        $result = (new WhatsappContactImporter)->import($csv, $phonebook);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        $this->assertCount(2, $result['errors']);

        // Each error names the row so it can be found in the file again.
        $this->assertStringContainsString('Row 3', $result['errors'][0]);
        $this->assertStringContainsString('Row 4', $result['errors'][1]);

        $this->assertSame(1, WhatsappContact::count());
    }

    public function test_a_repeated_phone_number_updates_the_existing_contact_instead_of_duplicating_it(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);

        $csv = $this->csv(
            "Phone,Name\n"
            ."7094126823,Ravi\n"
            ."7094126823,Ravi Kumar\n"
        );

        $result = (new WhatsappContactImporter)->import($csv, $phonebook);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(1, WhatsappContact::count());
        $this->assertSame('Ravi Kumar', WhatsappContact::sole()->name);

        // Attaching the same contact twice must not leave two pivot rows.
        $this->assertSame(1, $phonebook->fresh()->contactsCount());
        $this->assertDatabaseCount('whatsapp_contact_phonebook', 1);
    }

    /**
     * Re-running the same file against a phonebook it is already attached to
     * must be safe -- syncWithoutDetaching, not attach, is what makes an
     * import idempotent.
     */
    public function test_importing_the_same_file_twice_does_not_duplicate_the_pivot_row(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $csv = $this->csv("Phone,Name\n7094126823,Ravi\n");

        (new WhatsappContactImporter)->import($csv, $phonebook);
        (new WhatsappContactImporter)->import($csv, $phonebook);

        $this->assertSame(1, WhatsappContact::count());
        $this->assertDatabaseCount('whatsapp_contact_phonebook', 1);
    }

    public function test_the_summary_has_the_documented_shape(): void
    {
        $phonebook = WhatsappPhonebook::create(['name' => 'Leads']);
        $csv = $this->csv("Phone,Name\n7094126823,Ravi\n");

        $result = (new WhatsappContactImporter)->import($csv, $phonebook);

        $this->assertSame(['imported', 'skipped', 'errors'], array_keys($result));
        $this->assertIsInt($result['imported']);
        $this->assertIsInt($result['skipped']);
        $this->assertIsArray($result['errors']);
    }
}
