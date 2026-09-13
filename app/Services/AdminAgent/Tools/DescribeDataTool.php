<?php

namespace App\Services\AdminAgent\Tools;

use App\Models\User;
use App\Services\AdminAgent\ReadOnlyQuery;
use App\Services\AdminAgent\Tool;

/**
 * What there is to read, so the model can write a query against it.
 *
 * Answered in two steps on purpose. Ninety-odd tables with every column
 * spelled out is several thousand tokens, and the free tier allows eight
 * thousand a minute -- so asking with no filter gets the table names only,
 * and asking with one gets the columns for the two or three tables that
 * matter. A question costs a list and a lookup rather than the whole schema.
 */
class DescribeDataTool implements Tool
{
    public function name(): string
    {
        return 'describe_data';
    }

    public function definition(): array
    {
        return [
            'name' => 'describe_data',
            'description' => 'List what can be read. With no argument it returns every table name — do that first whenever you are not certain which table holds the thing being asked about. With `like` it returns the columns of the matching tables. Call this before run_query so the query uses real column names, and never guess a column. Finding the columns is a step towards answering, never a reason to stop.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'like' => [
                        'type' => 'string',
                        'description' => 'Part of a table name, e.g. "invoice", "timesheet", "shoot". Leave out to see every table name.',
                    ],
                ],
            ],
        ];
    }

    public function run(User $admin, array $input): string
    {
        $tables = ReadOnlyQuery::tables();
        $like = mb_strtolower(trim((string) ($input['like'] ?? '')));

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

        foreach (array_slice($matches, 0, 8) as $table) {
            $lines[] = $table.': '.implode(', ', ReadOnlyQuery::columns($table));
        }

        if (count($matches) > 8) {
            $lines[] = '…and '.(count($matches) - 8).' more tables match. Be more specific.';
        }

        return implode("\n", $lines);
    }
}
