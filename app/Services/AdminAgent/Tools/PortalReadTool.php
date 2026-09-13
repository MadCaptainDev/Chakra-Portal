<?php

namespace App\Services\AdminAgent\Tools;

use App\Models\User;
use App\Services\AdminAgent\Tool;
use App\Support\AdminPortal;

/**
 * The four figures the owner menu already answers, as tools.
 *
 * Deliberately the same code path as the menu (AdminPortal), not a second
 * implementation of the same questions. A figure that reads differently on
 * the assistant than on the menu -- or than on the dashboard, which
 * AdminPortal itself matches -- is worse than no figure, because now somebody
 * has to work out which of the three lied.
 *
 * One class, four instances: the question differs, the shape does not.
 */
class PortalReadTool implements Tool
{
    /** @var array<string, array{description: string, answer: callable}> */
    private const QUESTIONS = [
        'money_summary' => [
            'description' => 'Money collected so far this month and the total still outstanding across every client. Use for "how are we doing", "what did we collect", "how much is owed".',
            'answer' => [AdminPortal::class, 'money'],
        ],
        'overdue_invoices' => [
            'description' => 'Unpaid invoices that are past their due date, worst first, with how many days late each is.',
            'answer' => [AdminPortal::class, 'overdue'],
        ],
        'todays_shoots' => [
            'description' => 'Everything shooting today: title, call time, location and which crew are assigned. Only today -- use shoots_between for any other day.',
            'answer' => [AdminPortal::class, 'todaysShoots'],
        ],
        'timesheet_gaps' => [
            'description' => 'Which staff did not log their timesheet yesterday.',
            'answer' => [AdminPortal::class, 'timesheetGaps'],
        ],
    ];

    public function __construct(private readonly string $question) {}

    /** @return list<self> */
    public static function all(): array
    {
        return array_map(
            fn (string $question) => new self($question),
            array_keys(self::QUESTIONS),
        );
    }

    public function name(): string
    {
        return $this->question;
    }

    public function definition(): array
    {
        return [
            'name' => $this->question,
            'description' => self::QUESTIONS[$this->question]['description'],
            /*
             * No arguments at all: these four answer one question each.
             * `properties` is left out rather than set to an empty array --
             * an empty PHP array serialises to `[]`, and a JSON Schema whose
             * properties are a list rather than an object is rejected.
             */
            'inputSchema' => ['type' => 'object'],
        ];
    }

    public function run(User $admin, array $input): string
    {
        return call_user_func(self::QUESTIONS[$this->question]['answer']);
    }
}
