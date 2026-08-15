<?php

namespace App\Mcp;

use App\Models\User;

/**
 * One thing Claude can do to the portal.
 *
 * Every tool runs *as a person*. There is no service account and no way for a
 * tool to reach past its caller: the User handed to handle() is the owner of
 * the token that made the request, and each tool scopes its own queries to them
 * exactly as the equivalent controller does. A tool is a second front door onto
 * the same rooms, not a back one.
 */
abstract class Tool
{
    /** The name the model calls. snake_case, because that is the convention. */
    abstract public function name(): string;

    /**
     * What it does, written for a model rather than a developer.
     *
     * This is the single highest-leverage string in the whole feature: it is
     * how the model decides whether to reach for this tool at all. It should
     * say what the tool returns, whose data it covers, and when NOT to use it.
     */
    abstract public function description(): string;

    /**
     * JSON Schema for the arguments.
     *
     * @return array<string, mixed>
     */
    abstract public function schema(): array;

    /**
     * Do the thing. Return an array to be sent as JSON, or a string.
     *
     * Throwing McpToolException is how a tool says "I could not" in a way the
     * model gets to read.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<mixed>|string
     */
    abstract public function handle(array $arguments, User $user): array|string;

    /**
     * Which module permission this needs, as "module.ability", or null when it
     * is something everybody may do with their own data.
     */
    public function permission(): ?string
    {
        return null;
    }

    /**
     * Does this tool change anything?
     *
     * Advertised to the client as a read-only hint so a cautious host can ask
     * before it runs. It is a hint and nothing more -- the actual protection is
     * that every write goes through the same ownership checks the web screens
     * use.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * The tool as the protocol describes it.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => $this->schema(),
            'annotations' => [
                'readOnlyHint' => $this->isReadOnly(),
                'destructiveHint' => false,
            ],
        ];
    }

    /**
     * Sugar for schemas, which are otherwise a wall of nested arrays.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected function object(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            // Nothing here takes free-form extras, and saying so lets a client
            // catch a hallucinated argument before it reaches the server.
            'additionalProperties' => false,
        ];
    }
}
