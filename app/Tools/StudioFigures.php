<?php

namespace App\Tools;

use App\Models\User;
use App\Support\AdminPortal;

/**
 * The four figures somebody running the studio asks for when they are not at
 * a laptop: money in and owed, what is late, what is shooting today, who did
 * not log yesterday.
 *
 * One class, four tools. The question differs; the shape does not.
 *
 * Deliberately the same code path as the owner's WhatsApp menu (AdminPortal),
 * not a second implementation of the same sums. A figure that reads
 * differently on the assistant than on the menu -- or than on the dashboard,
 * which AdminPortal itself matches -- is worse than no figure, because now
 * somebody has to work out which of the three lied.
 */
class StudioFigures extends Tool
{
    /** @var array<string, array{description: string, answer: callable}> */
    private const QUESTIONS = [
        'money_summary' => [
            'description' => 'Money collected so far this month and the total still outstanding across every client. Use for "how are we doing", "what did we collect", "how much is owed".',
            'answer' => [AdminPortal::class, 'money'],
        ],
        'overdue_invoices' => [
            'description' => 'Unpaid invoices past their due date, worst first, with how many days late each is.',
            'answer' => [AdminPortal::class, 'overdue'],
        ],
        'todays_shoots' => [
            'description' => 'Everything shooting today: title, call time, location and which crew are assigned. Today only — use list_shoots or shoots_between for any other day.',
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

    public function description(): string
    {
        return self::QUESTIONS[$this->question]['description'];
    }

    /**
     * The owner's, not a module's. Every one of these reads across every
     * client's money or the whole team's day -- there is no per-module
     * permission that means "and everybody else's too".
     */
    public function requiresAdmin(): bool
    {
        return true;
    }

    public function schema(): array
    {
        // No arguments at all: these four answer one question each.
        return $this->object([]);
    }

    public function handle(array $arguments, User $user): string
    {
        return call_user_func(self::QUESTIONS[$this->question]['answer']);
    }
}
