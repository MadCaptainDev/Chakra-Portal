<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Dates that arrive in a query string, turned into something safe to query on.
 *
 * A ?date= in the URL is typed by hand as often as it is clicked, so an
 * unreadable one has to fall back rather than throw a 500 at somebody who
 * deleted a character. Same shape as the resolveMonth() the timesheet
 * controllers each carry a copy of; those are left alone for now so a timesheet
 * regression cannot arrive disguised as a to-do bug.
 */
class PeriodInput
{
    /** One day, at midnight. Anything unparseable reads as today. */
    public static function day(?string $value): Carbon
    {
        if (! $value) {
            return today();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return today();
        }
    }
}
