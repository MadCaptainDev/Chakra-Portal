<?php

namespace App\Mcp;

/**
 * The JSON-RPC 2.0 shapes MCP speaks, and nothing else.
 *
 * Written by hand rather than pulled in as a package. The wire format is a
 * handful of envelopes and five error codes; a dependency here would be more
 * code to audit than the code it replaced, and this repository's rule about not
 * adding dependencies is a good one.
 */
class Protocol
{
    /**
     * Revisions this server understands, newest first.
     *
     * A client states which one it wants during initialize. If we know it, we
     * answer in it; if we do not, we answer with our newest and let the client
     * decide whether it can live with that -- which is what the specification
     * asks for, and is friendlier than refusing to talk to a client that is
     * merely newer than us.
     */
    public const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    /**
     * @param  mixed  $result
     * @return array<string, mixed>
     */
    public static function result(string|int|null $id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(string|int|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * A tool that failed, reported as a successful call carrying bad news.
     *
     * Deliberately not a JSON-RPC error. A protocol error means "this request
     * was malformed" and is handled by the client's plumbing; a tool that could
     * not do what was asked is something the *model* needs to read and react to
     * -- it might retry with a different date, or tell the person why. Sending
     * it as a protocol error hides it from the one party who can act on it.
     *
     * @return array<string, mixed>
     */
    public static function toolError(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    /**
     * A tool that worked. Arrays go out as pretty JSON in a text block, which
     * every client renders and every model reads.
     *
     * @param  array<mixed>|string  $value
     * @return array<string, mixed>
     */
    public static function toolResult(array|string $value): array
    {
        $text = is_string($value)
            ? $value
            : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * Is this a notification -- something the client does not want an answer
     * to? The absence of an id is the whole test.
     *
     * @param  array<string, mixed>  $message
     */
    public static function isNotification(array $message): bool
    {
        return ! array_key_exists('id', $message);
    }

    public static function negotiate(?string $requested): string
    {
        return in_array($requested, self::VERSIONS, true) ? $requested : self::VERSIONS[0];
    }
}
