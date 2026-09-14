<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Lets the assistant read any part of the portal, and only read it.
 *
 * The alternative was a tool per question, and the studio's questions do not
 * come in a fixed set: "who paid the most this month", "how much video did
 * Sanjai edit on the 1st", "which venture ate the most hours". Each of those
 * is a different join over a different corner of a 99-table schema. So the
 * model writes the query and this decides whether it may run.
 *
 * Read-only here means structurally read-only, not "the prompt says so". A
 * prompt can be argued with -- and the text this model reads includes client
 * names and notes that the studio's own customers wrote, which is exactly the
 * shape of thing that carries an instruction somebody hoped an assistant would
 * follow. So every refusal below is a string check on the SQL, before MySQL
 * ever sees it:
 *
 * - it must begin SELECT, and be one statement;
 * - a word that writes, locks, loads or executes anything is refused outright;
 * - the tables holding credentials, tokens and sessions do not exist as far as
 *   this is concerned, and neither do the columns holding secrets -- refused
 *   if mentioned at all, so `WHERE password LIKE 'a%'` cannot be used to read
 *   one letter at a time, and redacted again on the way out in case a
 *   `SELECT *` reached one anyway;
 * - and the whole thing is wrapped in an outer LIMIT, so a model that forgets
 *   one does not hand a phone forty thousand rows.
 *
 * The row and character caps are not only about the phone. The free tier
 * allows eight thousand tokens a minute; one careless SELECT would spend a
 * day's questions in a single answer.
 */
final class ReadOnlyQuery
{
    /** Enough to answer "who are the top few"; never enough to dump a table. */
    public const MAX_ROWS = 30;

    /** Roughly 600 tokens of result, whatever shape the rows are. */
    public const MAX_CHARACTERS = 2500;

    public const TIMEOUT_MS = 5000;

    /**
     * Tables this cannot see at all.
     *
     * Credentials and tokens because reading them is the one thing a leak
     * needs; sessions and framework plumbing because they answer no question
     * anybody would ask on WhatsApp and only cost tokens to look at.
     */
    private const FORBIDDEN_TABLES = [
        'ai_settings',
        'whatsapp_settings',
        'instagram_settings',
        'notion_settings',
        'push_settings',
        'competitor_settings',
        'client_credentials',
        'client_credential_views',
        'mcp_tokens',
        'push_tokens',
        'password_reset_tokens',
        'personal_access_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
    ];

    /**
     * Column names that are a secret wherever they appear.
     *
     * Matched as substrings against real column names, so `password`,
     * `remember_token` and anything ending `_secret` are all covered without
     * this list having to be kept in step with every future migration.
     */
    private const SECRET_COLUMNS = [
        'password',
        'secret',
        'token',
        'api_key',
        'private_key',
        'credential',
    ];

    /** Anything that is not reading. */
    private const FORBIDDEN_WORDS = [
        'insert', 'update', 'delete', 'replace', 'drop', 'alter', 'truncate',
        'create', 'grant', 'revoke', 'rename', 'lock', 'unlock', 'call',
        'execute', 'prepare', 'deallocate', 'set', 'load', 'outfile',
        'dumpfile', 'infile', 'handler', 'into', 'sleep', 'benchmark',
        'information_schema', 'performance_schema',
    ];

    /**
     * Every table the assistant may read, in the schema's own order.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        /*
         * Schema rather than SHOW TABLES: the tests run on SQLite and
         * production on MySQL, and a guard that can only be exercised
         * against production is a guard nobody exercises.
         *
         * The name is taken from after the last dot, because MySQL answers
         * this schema-qualified -- "u512425400_chakraportal.ai_settings" --
         * and SQLite does not. Comparing the qualified name against the
         * forbidden list matches nothing, which does not fail loudly: it
         * quietly makes every forbidden table readable. That is what this
         * line is for.
         */
        $tables = array_map(
            fn (string $table) => str_contains($table, '.') ? substr(strrchr($table, '.'), 1) : $table,
            Schema::getTableListing(),
        );

        $allowed = array_values(array_diff($tables, self::FORBIDDEN_TABLES));
        sort($allowed);

        return $allowed;
    }

    /**
     * A table's readable columns, secrets left out.
     *
     * @return list<string>
     */
    public static function columns(string $table): array
    {
        if (! in_array($table, self::tables(), true)) {
            return [];
        }

        return array_values(array_filter(
            Schema::getColumnListing($table),
            fn (string $column) => ! self::isSecret($column),
        ));
    }

    /**
     * Run a SELECT and render the rows, or refuse and say why.
     *
     * The refusal is a message, not a crash: it goes back to the model as the
     * tool's answer, so a query that reached for the wrong table becomes a
     * second, better query rather than a silent phone.
     *
     * @throws InvalidArgumentException when the SQL is not something this may run
     */
    public function run(string $sql): string
    {
        $sql = trim(rtrim(trim($sql), ';'));

        $this->refuseAnythingButOneSelect($sql);
        $this->refuseForbiddenWords($sql);
        $this->refuseForbiddenNames($sql);

        /*
         * The optimizer hint rather than a session variable: this class holds
         * one connection in common with the rest of the request, and a
         * `SET SESSION` here would quietly change the timeout for everything
         * that ran afterwards on it.
         */
        $wrapped = 'SELECT /*+ MAX_EXECUTION_TIME('.self::TIMEOUT_MS.') */ * FROM ('
            .$sql
            .') AS agent_query LIMIT '.(self::MAX_ROWS + 1);

        $rows = DB::select($wrapped);

        return $this->render($rows);
    }

    private function refuseAnythingButOneSelect(string $sql): void
    {
        if ($sql === '') {
            throw new InvalidArgumentException('No query was given.');
        }

        if (str_contains($sql, ';')) {
            throw new InvalidArgumentException('One statement at a time, and no semicolons.');
        }

        if (preg_match('/^select\b/i', $sql) !== 1) {
            throw new InvalidArgumentException('Only SELECT is allowed. This can read the portal and change nothing.');
        }
    }

    private function refuseForbiddenWords(string $sql): void
    {
        foreach (self::FORBIDDEN_WORDS as $word) {
            /*
             * Boundaries of letters and digits only -- deliberately not \b,
             * which counts an underscore as part of a word. `\bload\b` does
             * not match LOAD_FILE, and LOAD_FILE('/etc/passwd') is a file
             * read. Treating `_` as a boundary catches the whole family of
             * underscore-named functions at once.
             *
             * It still lets the ordinary columns through, which is the other
             * half of the job: `updated_at` is UPDATE followed by a letter,
             * `deleted_at` and `created_at` likewise, and `offset` is SET
             * preceded by one. Only a real verb standing on its own matches.
             */
            if (preg_match('/(?<![a-z0-9])'.preg_quote($word, '/').'(?![a-z0-9])/i', $sql) === 1) {
                throw new InvalidArgumentException('That query uses "'.$word.'", which is not something a read can do.');
            }
        }
    }

    /**
     * Tables and columns that are off limits, refused for being mentioned at
     * all rather than only for being selected.
     *
     * `WHERE password LIKE 'a%'` returns no password and still reads one --
     * a row either comes back or it does not, and a few hundred of those
     * spell it out. Redacting the output alone would not stop that.
     */
    private function refuseForbiddenNames(string $sql): void
    {
        foreach (self::FORBIDDEN_TABLES as $table) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($table, '/').'(?![a-z0-9])/i', $sql) === 1) {
                throw new InvalidArgumentException('The '.$table.' table holds credentials or plumbing and cannot be read.');
            }
        }

        foreach (self::SECRET_COLUMNS as $fragment) {
            if (str_contains(mb_strtolower($sql), $fragment)) {
                throw new InvalidArgumentException('That query mentions "'.$fragment.'", and secrets cannot be read or filtered on.');
            }
        }
    }

    /**
     * Rows as something a small model can read back cheaply: one header line,
     * then one line per row. Not JSON -- the braces and repeated keys would
     * cost more tokens than the figures.
     *
     * @param  list<object>  $rows
     */
    private function render(array $rows): string
    {
        if ($rows === []) {
            return 'No rows matched.';
        }

        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $columns = array_keys((array) $rows[0]);
        $lines = [implode(' | ', $columns)];

        foreach ($rows as $row) {
            $values = [];

            foreach ((array) $row as $column => $value) {
                $values[] = self::isSecret((string) $column)
                    // A `SELECT *` over a table with one of these still gets
                    // its rows; it just does not get the secret.
                    ? '[hidden]'
                    : (string) ($value ?? '');
            }

            $lines[] = implode(' | ', $values);
        }

        if ($truncated) {
            $lines[] = 'Only the first '.self::MAX_ROWS.' rows are shown — add a tighter WHERE, or aggregate.';
        }

        $rendered = implode("\n", $lines);

        if (mb_strlen($rendered) > self::MAX_CHARACTERS) {
            return mb_substr($rendered, 0, self::MAX_CHARACTERS)
                ."\n[cut off — too much to read out. Select fewer columns, or aggregate.]";
        }

        return $rendered;
    }

    private static function isSecret(string $column): bool
    {
        foreach (self::SECRET_COLUMNS as $fragment) {
            if (str_contains(mb_strtolower($column), $fragment)) {
                return true;
            }
        }

        return false;
    }
}
