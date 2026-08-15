<?php

namespace App\Mcp\Tools;

use App\Mcp\McpToolException;
use App\Models\User;

/**
 * Turning "Aron" into a user, safely.
 *
 * A model works in names, not ids, so every tool that can be pointed at
 * somebody else has to do this lookup -- and every one of them has to get the
 * permission check right. Doing it once here means there is one place to be
 * sure about rather than five.
 */
class People
{
    /**
     * Who the caller means, defaulting to the caller themselves.
     *
     * The permission check is deliberately the same one the team timesheet
     * screen uses: their manager, or an admin. A name that exists but is not
     * theirs to read is refused in the same words as a name that does not
     * exist, so the tool cannot be used to find out who works here.
     */
    public static function resolve(?string $person, User $caller): User
    {
        if ($person === null || trim($person) === '') {
            return $caller;
        }

        $needle = trim($person);

        if (strcasecmp($needle, $caller->name) === 0 || strcasecmp($needle, $caller->email) === 0) {
            return $caller;
        }

        // Grouped, so staff() is ANDed with the name-or-email match rather
        // than the orWhere escaping it and matching a client login.
        $matches = User::staff()
            ->where(fn ($query) => $query
                ->where('email', $needle)
                ->orWhere('name', 'like', $needle.'%'))
            ->orderBy('name')
            ->get();

        if ($matches->count() > 1) {
            throw new McpToolException(
                'More than one person matches "'.$needle.'": '
                .$matches->pluck('name')->implode(', ').'. Use a full name or an email address.'
            );
        }

        $subject = $matches->first();

        if (! $subject || ! $caller->managesTimesheetOf($subject)) {
            throw new McpToolException(
                'No-one called "'.$needle.'" whose work you can read. You can read your own, '
                .'and an admin or their manager can read anybody\'s.'
            );
        }

        return $subject;
    }

    /**
     * Who the caller may hand work to: anybody with a login.
     *
     * Assigning is not gated in this studio -- a producer hands an edit to an
     * editor without asking permission, and the to-do records who asked. So the
     * only failure here is a name that matches nobody or matches too many.
     */
    public static function assignable(?string $person, User $caller): User
    {
        if ($person === null || trim($person) === '') {
            return $caller;
        }

        $needle = trim($person);

        if (strcasecmp($needle, $caller->name) === 0 || strcasecmp($needle, $caller->email) === 0) {
            return $caller;
        }

        // Grouped, so staff() is ANDed with the name-or-email match rather
        // than the orWhere escaping it and matching a client login.
        $matches = User::staff()
            ->where(fn ($query) => $query
                ->where('email', $needle)
                ->orWhere('name', 'like', $needle.'%'))
            ->orderBy('name')
            ->get();

        if ($matches->isEmpty()) {
            throw new McpToolException('Nobody here is called "'.$needle.'".');
        }

        if ($matches->count() > 1) {
            throw new McpToolException(
                'More than one person matches "'.$needle.'": '
                .$matches->pluck('name')->implode(', ').'. Use a full name or an email address.'
            );
        }

        return $matches->first();
    }
}
