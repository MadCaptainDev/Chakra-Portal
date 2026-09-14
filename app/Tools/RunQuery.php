<?php

namespace App\Tools;

use App\Models\User;
use App\Services\ReadOnlyQuery;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * The tool that answers a question nobody wrote a tool for.
 *
 * The four studio figures answer the four commonest questions. This answers
 * the rest of them -- who paid the most this month and what share that is, how
 * many minutes of editing one person logged on one day, which venture is
 * taking the hours. Those are joins and sums over a schema too wide to wrap
 * one tool at a time.
 *
 * Refusals come back as ordinary text rather than as failures, because a
 * refused query is usually a fixable one: the caller reads why, and asks
 * again. ReadOnlyQuery owns every rule about what "readable" means.
 */
class RunQuery extends Tool
{
    public function __construct(private readonly ReadOnlyQuery $queries = new ReadOnlyQuery) {}

    public function name(): string
    {
        return 'run_query';
    }

    public function description(): string
    {
        return implode(' ', [
            'Run one read-only MySQL SELECT and get the rows back. Use it for anything the other tools do not answer: totals, shares, per-person or per-client breakdowns, any date range.',
            'Call describe_data first for real column names — a guessed column is an error, not an answer.',
            'Aggregate in SQL (SUM, COUNT, GROUP BY, ROUND) rather than adding rows up yourself.',
            'payments (amount, paid_on, invoice_id) is money received; invoices (total, status, due_date, client_id) is money billed;',
            'timesheet_entries (minutes, task_type of editing/shooting/posting/other, worked_on, user_id, venture) is hours worked; shoots is the diary.',
            'Join users.id for a person\'s name, clients.id for a client\'s. SELECT only.',
        ]);
    }

    /**
     * Every table there is, which is every client's money and every person's
     * pay. There is no module permission that means that; this one is the
     * owner's alone.
     */
    public function requiresAdmin(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'sql' => ['type' => 'string', 'description' => 'One MySQL SELECT statement, no trailing semicolon.'],
        ], ['sql']);
    }

    public function handle(array $arguments, User $user): string
    {
        try {
            return $this->queries->run((string) ($arguments['sql'] ?? ''));
        } catch (InvalidArgumentException $e) {
            // A rule was broken. Say which, so the next attempt is a better
            // query rather than the same one.
            return 'Refused: '.$e->getMessage();
        } catch (QueryException $e) {
            /*
             * MySQL's own complaint -- an invented column, usually. Trimmed to
             * the first line: the full message repeats the entire query and a
             * connection dump, which on an eight-thousand-token minute is most
             * of the budget spent saying "no such column".
             */
            return 'SQL error: '.mb_substr(explode("\n", $e->getMessage())[0], 0, 200)
                .' — check describe_data for the real column names.';
        }
    }
}
