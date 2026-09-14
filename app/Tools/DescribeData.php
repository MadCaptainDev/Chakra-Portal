<?php

namespace App\Tools;

use App\Models\User;
use App\Services\ReadOnlyQuery;

/**
 * What there is to read, so a query can be written against it.
 *
 * Answered in two steps on purpose. Ninety-odd tables with every column
 * spelled out is several thousand tokens, and the assistant's free tier allows
 * eight thousand a minute -- so asking with no filter gets the table names
 * only, and asking with one gets the columns for the two or three that matter.
 */
class DescribeData extends Tool
{
    private const MAX_TABLES = 8;

    public function name(): string
    {
        return 'describe_data';
    }

    public function description(): string
    {
        return 'List what can be read. With no argument it returns every table name — do that first '
            .'whenever you are not certain which table holds the thing being asked about. With `like` '
            .'it returns the columns of the matching tables. Call this before run_query so the query '
            .'uses real column names, and never guess a column. Finding the columns is a step towards '
            .'answering, never a reason to stop.';
    }

    /**
     * The whole schema, so the whole studio: this is the owner's, the same as
     * run_query which it exists to serve.
     */
    public function requiresAdmin(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'like' => ['type' => 'string', 'description' => 'Part of a table name, e.g. "invoice", "timesheet", "shoot". Leave out to see every table name.'],
        ]);
    }

    public function handle(array $arguments, User $user): string
    {
        $tables = ReadOnlyQuery::tables();
        $like = mb_strtolower(trim((string) ($arguments['like'] ?? '')));

        if ($like === '') {
            return "Tables (call describe_data again with `like` to see a table's columns):\n"
                .implode(', ', $tables);
        }

        $matches = array_values(array_filter(
            $tables,
            fn (string $table) => str_contains($table, $like),
        ));

        if ($matches === []) {
            return 'No table name contains "'.$like.'". Call describe_data with no argument to see them all.';
        }

        $lines = [];

        foreach (array_slice($matches, 0, self::MAX_TABLES) as $table) {
            $lines[] = $table.': '.implode(', ', ReadOnlyQuery::columns($table));
        }

        if (count($matches) > self::MAX_TABLES) {
            $lines[] = '…and '.(count($matches) - self::MAX_TABLES).' more tables match. Be more specific.';
        }

        return implode("\n", $lines);
    }
}
