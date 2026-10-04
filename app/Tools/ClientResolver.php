<?php

namespace App\Tools;

use App\Models\Client;

/**
 * Turn whatever a model passes as "the client" into exactly one Client, or
 * refuse.
 *
 * Tools that WRITE against a client use this, never a loose LIKE: a model
 * says "Riya" and means "Riya Makeover Artisty", which is fine only when it
 * is the one client that fits. Two candidates is an error that lists both
 * with their ids, so the model asks rather than guesses -- logging ₹5,000
 * against the wrong client is the misuse this exists to prevent. Every tool
 * echoes the resolved name back, so the person sees who it landed on.
 */
class ClientResolver
{
    public static function resolve(mixed $value): Client
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new ToolException('Say which client: their id, or their name as it is in the portal.');
        }

        if (ctype_digit($value)) {
            return Client::find((int) $value)
                ?? throw new ToolException("There is no client with id {$value}. Use find_client to look one up.");
        }

        $exact = Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->get();

        if ($exact->count() === 1) {
            return $exact->first();
        }

        $partial = Client::query()->where('name', 'like', '%'.$value.'%')->orderBy('name')->limit(6)->get();

        if ($partial->count() === 1) {
            return $partial->first();
        }

        if ($partial->isEmpty()) {
            throw new ToolException("No client matches \"{$value}\". Use find_client to look one up.");
        }

        throw new ToolException(
            "\"{$value}\" matches more than one client — ask the person which one, then pass its id: "
            .$partial->map(fn (Client $c) => "{$c->name} (id {$c->id})")->implode('; ').'.'
        );
    }
}
