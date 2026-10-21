<?php

namespace App\Support;

use App\Tools\Clients;
use App\Tools\ToolException;
use Illuminate\Support\Str;

/**
 * Which client a video title is for, from the way the team writes them:
 * "SVA - Sharara Kurti Set" is SVA Silks, "Suryas - Cream Bun Montage" is
 * Surya's Restaurant, "Mahavir - Door Ad" is Mahavir Timbers. Anything it
 * cannot place is all / multiple clients -- the title still says who.
 */
final class ClientGuess
{
    /** The client part of a title: everything before the first " - ". */
    public static function prefix(string $title): string
    {
        $parts = preg_split('/\s+[-–]\s+/u', trim($title), 2);

        return count($parts) === 2 ? trim($parts[0]) : '';
    }

    public static function venture(string $token): string
    {
        if (trim($token) === '') {
            return TimesheetVenture::ALL_CLIENTS;
        }
        try {
            return Clients::match($token);
        } catch (ToolException) {
            $starts = array_values(array_filter(
                TimesheetVenture::allowedValues(),
                fn ($v) => Str::startsWith(Str::lower($v), Str::lower(trim($token)))
            ));

            return count($starts) === 1 ? $starts[0] : TimesheetVenture::ALL_CLIENTS;
        }
    }

    public static function forTitle(string $title): string
    {
        return self::venture(self::prefix($title));
    }
}
