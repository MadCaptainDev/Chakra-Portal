<?php

namespace App\Mcp\Tools;

use App\Mcp\McpToolException;
use App\Support\TimesheetVenture;

/**
 * Turning what a model typed into a client the studio actually has.
 *
 * The client on a timesheet entry and on a to-do come from one fixed list, and
 * the whole point of that list is that "SVA", "SVA Silks" and "sva silks" all
 * end up as the same string -- otherwise a month's hours are split across three
 * spellings of one customer. A model inventing a plausible name is exactly the
 * failure this has to catch, so an unmatched name is refused with the real list
 * attached rather than stored.
 */
class Clients
{
    public static function match(?string $value): string
    {
        // Work spanning several clients has its own entry on the list, and is
        // the honest default when nothing was said.
        if ($value === null || trim($value) === '') {
            return TimesheetVenture::ALL_CLIENTS;
        }

        $needle = trim($value);
        $allowed = TimesheetVenture::allowedValues();

        foreach ($allowed as $option) {
            if (strcasecmp($option, $needle) === 0) {
                return $option;
            }
        }

        // The same normaliser the spreadsheet importer uses, so "Riya-Smudge
        // Proof" lands on "Riya" here exactly as it does there.
        $normalised = TimesheetVenture::normalize($needle);

        if ($normalised !== null) {
            foreach ($allowed as $option) {
                if (strcasecmp($option, $normalised) === 0) {
                    return $option;
                }
            }
        }

        throw new McpToolException(
            'There is no client called "'.$needle.'". The ones on file are: '.implode(', ', $allowed).'.'
        );
    }
}
