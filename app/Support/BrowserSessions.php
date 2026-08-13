<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Where an account is signed in, and signing a device out again.
 *
 * Reads Laravel's own `sessions` table, which is already the source of truth
 * because SESSION_DRIVER is `database`. Nothing new is recorded: a session row
 * is written on every request and carries the IP and user agent by itself.
 *
 * A session is never identified in the page by its id. That id *is* the cookie
 * value -- anyone who reads it off a screen or out of the DOM is signed in as
 * that person on that device. Every session is given a one-way handle instead,
 * and removing a device means finding which of this user's sessions hashes to
 * the handle that came back. The id stays in the database where it belongs.
 */
class BrowserSessions
{
    /**
     * Every session of this user's that is still alive, most recent first.
     *
     * Sessions past the configured lifetime are excluded rather than shown as
     * stale. Laravel prunes them on a lottery, so a browser closed last week
     * can easily still have a row -- and listing it would tell somebody they
     * are signed in somewhere they are not, which is the one thing this screen
     * must never do.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function for(User $user, ?string $currentId = null): Collection
    {
        $cutoff = now()->subMinutes((int) Config::get('session.lifetime', 120))->getTimestamp();

        return collect(
            DB::table(Config::get('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('last_activity', '>=', $cutoff)
                ->orderByDesc('last_activity')
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
        )->map(fn (object $session) => [
            'handle' => self::handle($session->id),
            'isCurrent' => $currentId !== null && hash_equals($session->id, $currentId),
            'ip' => $session->ip_address,
            'lastActive' => Carbon::createFromTimestamp($session->last_activity),
            ...Device::describe($session->user_agent),
        ])->values();
    }

    /**
     * Sign one device out, by the handle the page was given.
     *
     * Scoped to this user's own rows, so a handle guessed or copied from
     * somebody else's screen matches nothing. Returns what was signed out, or
     * null if the handle matched no live session of theirs -- which is what a
     * second click on the same button looks like, and is not an error.
     */
    public static function forget(User $user, string $handle): ?string
    {
        $table = Config::get('session.table', 'sessions');

        $rows = DB::table($table)->where('user_id', $user->id)->get(['id', 'user_agent']);

        foreach ($rows as $row) {
            if (hash_equals(self::handle($row->id), $handle)) {
                DB::table($table)->where('id', $row->id)->delete();

                return Device::describe($row->user_agent)['label'];
            }
        }

        return null;
    }

    /**
     * Sign out every device except the one asking. Returns how many went.
     */
    public static function forgetOthers(User $user, string $currentId): int
    {
        return DB::table(Config::get('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentId)
            ->delete();
    }

    /**
     * Does this handle name that session? Compared in constant time.
     */
    public static function matches(string $handle, string $sessionId): bool
    {
        return hash_equals(self::handle($sessionId), $handle);
    }

    /**
     * A one-way name for a session, safe to put in a form.
     *
     * sha256 of the id: stable across requests so the button keeps working,
     * and useless to anyone who copies it, because it cannot be turned back
     * into the cookie it came from.
     */
    private static function handle(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
