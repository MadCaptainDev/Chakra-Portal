<?php

namespace App\Services\AdminAgent;

use App\Mcp\Server;
use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;
use Throwable;

/**
 * The studio's tools, as the WhatsApp assistant sees them.
 *
 * Not a second list. The list lives in App\Mcp\Server -- one set of tools with
 * two front doors, the other being Claude on somebody's laptop -- and this is
 * the adapter that hands them to a model and runs what it picks. Adding a tool
 * there adds it here.
 *
 * Scoped to the person asking, through the same filter the MCP server uses: an
 * employee's tools are their own timesheet and their own to-dos, an admin's
 * are those plus every client's money. Nothing here decides that; Server does,
 * once, for both doors.
 */
class ToolRegistry
{
    public function __construct(private readonly Server $server) {}

    /** @return array<string, Tool> */
    public function for(User $user): array
    {
        $tools = [];

        foreach ($this->server->toolsFor($user) as $tool) {
            $tools[$tool->name()] = $tool;
        }

        return $tools;
    }

    /**
     * The definitions, as a model wants them, in a fixed order.
     *
     * Fixed because the tool list is the first thing in a cached prompt
     * prefix: a set arriving in a different order each time invalidates the
     * cache on every call and quietly doubles the input bill.
     *
     * describe() also carries MCP's `annotations`, which mean nothing to the
     * Messages API and are dropped here rather than sent and ignored -- an
     * unknown field is a 400 waiting to happen, and they are tokens either
     * way.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(User $user): array
    {
        return array_values(array_map(
            fn (Tool $tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->schema(),
            ],
            $this->for($user),
        ));
    }

    /**
     * Run one, and render whatever it gives back as something a model reads.
     *
     * A name the model invented, or a tool that threw, comes back as an error
     * string rather than an exception. The model can then tell the owner which
     * part it could not get, which is a better answer than a job that dies and
     * a phone that stays silent.
     *
     * @param  array<string, mixed>  $input
     * @return array{output: string, failed: bool}
     */
    public function run(string $name, User $user, array $input): array
    {
        $tool = $this->for($user)[$name] ?? null;

        if ($tool === null) {
            return ['output' => 'There is no tool called "'.$name.'".', 'failed' => true];
        }

        try {
            return ['output' => self::render($tool->handle($input, $user)), 'failed' => false];
        } catch (ToolException $e) {
            // The tool saying "I could not", in words it chose for a model to
            // read. Not a failure of the machinery.
            return ['output' => $e->getMessage(), 'failed' => true];
        } catch (Throwable $e) {
            report($e);

            return ['output' => 'That lookup failed: '.$e->getMessage(), 'failed' => true];
        }
    }

    /**
     * Tools written for MCP answer with arrays, because a JSON-RPC client
     * wants structure. A model reading over WhatsApp wants prose, and pays by
     * the token for every brace -- so an array is flattened to `key: value`
     * lines rather than encoded.
     */
    private static function render(array|string $result): string
    {
        if (is_string($result)) {
            return $result;
        }

        $lines = [];

        foreach ($result as $key => $value) {
            $lines[] = is_scalar($value) || $value === null
                ? $key.': '.(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value)
                : $key.': '.json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }
}
