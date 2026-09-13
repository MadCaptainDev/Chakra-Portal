<?php

namespace App\Services\AdminAgent;

use App\Models\User;
use App\Services\AdminAgent\Tools\DescribeDataTool;
use App\Services\AdminAgent\Tools\FindClientTool;
use App\Services\AdminAgent\Tools\InvoiceLookupTool;
use App\Services\AdminAgent\Tools\PortalReadTool;
use App\Services\AdminAgent\Tools\RunQueryTool;
use App\Services\AdminAgent\Tools\ShootsBetweenTool;
use Throwable;

/**
 * Everything the assistant can do, and the only way it can do any of it.
 *
 * One list, in one place, so the answer to "what can it touch" is a file
 * somebody can read rather than a grep. Every tool here reads; none writes.
 * That is this phase's boundary and it is enforced by the list rather than by
 * intention -- a write cannot happen because no tool here performs one.
 *
 * That still holds now the list ends with run_query, which can reach any
 * table in the schema: what makes it true there is ReadOnlyQuery, which
 * refuses anything but a single SELECT before MySQL is handed a word of it.
 *
 * A name the model invents, or a tool that throws, comes back as an error
 * string rather than an exception. The model can then tell the admin which
 * part it could not get, which is a better answer than a job that dies and a
 * phone that stays silent.
 */
class ToolRegistry
{
    /** @var array<string, Tool>|null */
    private ?array $tools = null;

    /** @return array<string, Tool> */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $tools = [
            ...PortalReadTool::all(),
            new FindClientTool,
            new InvoiceLookupTool,
            new ShootsBetweenTool,
            /*
             * Last, and deliberately so: the ones above answer the common
             * questions in one call with wording already shaped for a phone,
             * and a model that reaches for SQL to ask what is overdue spends
             * three calls getting to a worse version of the same answer.
             * These two are for everything nobody wrote a tool for.
             */
            new DescribeDataTool,
            new RunQueryTool,
        ];

        $this->tools = [];

        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }

        return $this->tools;
    }

    /**
     * The definitions, in a fixed order.
     *
     * Fixed because the tool list is the first thing in a cached prompt
     * prefix: a set that arrives in a different order each time invalidates
     * the cache on every single call and quietly doubles the input bill.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_values(array_map(
            fn (Tool $tool) => $tool->definition(),
            $this->all(),
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{output: string, failed: bool}
     */
    public function run(string $name, User $admin, array $input): array
    {
        $tool = $this->all()[$name] ?? null;

        if ($tool === null) {
            return ['output' => 'There is no tool called "'.$name.'".', 'failed' => true];
        }

        try {
            return ['output' => $tool->run($admin, $input), 'failed' => false];
        } catch (Throwable $e) {
            report($e);

            return ['output' => 'That lookup failed: '.$e->getMessage(), 'failed' => true];
        }
    }
}
