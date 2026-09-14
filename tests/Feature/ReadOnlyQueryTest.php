<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\ReadOnlyQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The guard that lets the assistant read the whole portal and nothing else.
 *
 * This is the file to be strict in. Everywhere else a bug means a wrong
 * answer; here it means the studio's credentials, or a DELETE, reachable by
 * asking a chatbot nicely. Every rule ReadOnlyQuery enforces gets a test that
 * would fail if somebody removed it.
 */
class ReadOnlyQueryTest extends TestCase
{
    use RefreshDatabase;

    private ReadOnlyQuery $queries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queries = new ReadOnlyQuery;
    }

    private function refuses(string $sql, string $because): void
    {
        try {
            $this->queries->run($sql);
            $this->fail('Expected to be refused: '.$sql);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }

    // ——— What it will do ———

    public function test_a_select_returns_its_rows(): void
    {
        Client::factory()->create(['name' => 'Pothys Silks']);
        Client::factory()->create(['name' => 'Janet Hospitals']);

        $result = $this->queries->run('SELECT name FROM clients ORDER BY name');

        $this->assertStringContainsString('Janet Hospitals', $result);
        $this->assertStringContainsString('Pothys Silks', $result);
    }

    public function test_an_aggregate_comes_back_as_one_row(): void
    {
        Client::factory()->count(3)->create();

        $this->assertStringContainsString('3', $this->queries->run('SELECT COUNT(*) AS total FROM clients'));
    }

    public function test_nothing_matching_says_so_rather_than_returning_nothing(): void
    {
        // The model has to be able to tell "no overdue invoices" from "the
        // query broke", and an empty string reads as neither.
        $this->assertSame('No rows matched.', $this->queries->run("SELECT id FROM clients WHERE name = 'nobody'"));
    }

    // ——— What it will not do ———

    public function test_every_way_of_writing_is_refused(): void
    {
        $this->refuses("INSERT INTO clients (name) VALUES ('x')", 'Only SELECT');
        $this->refuses("UPDATE clients SET name = 'x'", 'Only SELECT');
        $this->refuses('DELETE FROM clients', 'Only SELECT');
        $this->refuses('DROP TABLE clients', 'Only SELECT');
        $this->refuses('TRUNCATE clients', 'Only SELECT');
        $this->refuses('ALTER TABLE clients ADD COLUMN x INT', 'Only SELECT');
        $this->refuses('CREATE TABLE x (id INT)', 'Only SELECT');
        $this->refuses('GRANT ALL ON *.* TO x', 'Only SELECT');
    }

    public function test_a_write_smuggled_after_a_select_is_refused(): void
    {
        // The classic: a statement that starts innocently and does not end
        // there. Refused for the semicolon before anything reads the rest.
        $this->refuses('SELECT 1; DROP TABLE clients', 'One statement');
        $this->refuses('SELECT 1; DELETE FROM users', 'One statement');
    }

    public function test_a_write_word_inside_a_select_is_refused(): void
    {
        $this->refuses('SELECT * FROM clients WHERE id IN (SELECT id FROM clients) UNION SELECT 1 INTO OUTFILE "/tmp/x"', 'not something a read can do');
        // LOAD_FILE reads a file off the server. It is the reason the word
        // guard treats an underscore as a boundary: \b does not, so `\bload\b`
        // sails straight past LOAD_FILE.
        $this->refuses('SELECT LOAD_FILE("/etc/shadow")', 'not something a read can do');
        $this->refuses('SELECT SLEEP(30)', 'sleep');
        $this->refuses('SELECT * FROM information_schema.tables', 'information_schema');
    }

    public function test_the_tables_holding_credentials_do_not_exist_here(): void
    {
        foreach (['ai_settings', 'whatsapp_settings', 'client_credentials', 'sessions', 'password_reset_tokens'] as $table) {
            $this->refuses('SELECT * FROM '.$table, 'cannot be read');
        }
    }

    public function test_a_secret_column_cannot_be_read_one_letter_at_a_time(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN]);

        /*
         * The attack this exists for: the password is never returned, and a
         * few hundred of these still spell it out from which rows come back.
         * Redacting the output alone would not stop it, so mentioning the
         * column at all is refused.
         */
        $this->refuses("SELECT id FROM users WHERE password LIKE 'a%'", 'secrets cannot be read');
        $this->refuses('SELECT remember_token FROM users', 'secrets cannot be read');
    }

    public function test_a_select_star_still_hides_the_secrets_it_reaches(): void
    {
        User::factory()->create(['name' => 'Sanjai', 'role' => User::ROLE_EMPLOYEE]);

        $result = $this->queries->run('SELECT * FROM users');

        // The row comes back; the password does not.
        $this->assertStringContainsString('Sanjai', $result);
        $this->assertStringContainsString('[hidden]', $result);
        $this->assertStringNotContainsString('$2y$', $result);
    }

    // ——— Where the guard must not over-reach ———

    public function test_the_common_timestamp_columns_survive_the_word_guard(): void
    {
        Client::factory()->create(['name' => 'Pothys Silks']);

        // created_at / updated_at / deleted_at all contain a forbidden verb.
        // A guard that refused them would refuse most honest queries.
        $result = $this->queries->run('SELECT name, created_at, updated_at FROM clients');

        $this->assertStringContainsString('Pothys Silks', $result);
    }

    public function test_a_column_that_merely_contains_a_forbidden_word_is_fine(): void
    {
        Client::factory()->create(['name' => 'Pothys Silks']);

        // `updated_at` contains "update" and `offset` contains "set". A guard
        // that matched those would refuse most honest queries ever written.
        $result = $this->queries->run('SELECT name, updated_at FROM clients ORDER BY updated_at DESC LIMIT 5 OFFSET 0');

        $this->assertStringContainsString('Pothys Silks', $result);
    }

    public function test_a_trailing_semicolon_is_forgiven_rather_than_refused(): void
    {
        Client::factory()->create(['name' => 'Pothys Silks']);

        // Models write them out of habit; refusing costs a whole round trip
        // to teach a lesson worth nothing.
        $this->assertStringContainsString('Pothys Silks', $this->queries->run('SELECT name FROM clients;'));
    }

    // ——— Size ———

    public function test_more_rows_than_the_cap_are_cut_off_and_said_to_be(): void
    {
        Client::factory()->count(ReadOnlyQuery::MAX_ROWS + 5)->create();

        $result = $this->queries->run('SELECT id, name FROM clients');

        // A phone cannot read forty rows and the free tier cannot afford to
        // send them.
        $this->assertStringContainsString('Only the first '.ReadOnlyQuery::MAX_ROWS.' rows', $result);
        $this->assertLessThanOrEqual(ReadOnlyQuery::MAX_ROWS + 2, substr_count($result, "\n") + 1);
    }

    public function test_a_very_wide_result_is_truncated(): void
    {
        Client::factory()->count(20)->create(['address' => str_repeat('a long address ', 40)]);

        $result = $this->queries->run('SELECT id, name, address FROM clients');

        $this->assertStringContainsString('cut off', $result);
        $this->assertLessThan(ReadOnlyQuery::MAX_CHARACTERS + 200, mb_strlen($result));
    }

    // ——— What the model is allowed to see of the schema ———

    public function test_the_table_list_leaves_out_what_cannot_be_read(): void
    {
        $tables = ReadOnlyQuery::tables();

        $this->assertContains('invoices', $tables);
        $this->assertContains('timesheet_entries', $tables);
        $this->assertNotContains('ai_settings', $tables);
        $this->assertNotContains('sessions', $tables);
    }

    public function test_the_column_list_leaves_out_the_secrets(): void
    {
        $columns = ReadOnlyQuery::columns('users');

        $this->assertContains('name', $columns);
        $this->assertContains('role', $columns);
        $this->assertNotContains('password', $columns);
        $this->assertNotContains('remember_token', $columns);
    }

    public function test_a_forbidden_table_has_no_columns_to_offer(): void
    {
        $this->assertSame([], ReadOnlyQuery::columns('ai_settings'));
    }
}
