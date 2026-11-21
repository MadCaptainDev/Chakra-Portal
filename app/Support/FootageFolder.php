<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Support\Carbon;

/**
 * Where a client's footage lands on the drive, for Chakra Planner's card
 * offload: <drive_root>/<pending path>/<prefix> - <video name>/.
 *
 * The pending path is a template because the drive is not consistent from
 * client to client ("Nov - 2026/pending", "Pending", "NOV- 2026/PENDING").
 * Placeholders are filled from the shoot's date.
 */
class FootageFolder
{
    public const DEFAULT_TEMPLATE = '{month_short} - {year}/pending';

    /** @var list<string> */
    public const PLACEHOLDERS = ['{month_short}', '{MONTH_SHORT}', '{month}', '{year}', '{mm}'];

    /** Placeholders in the template that are not one of PLACEHOLDERS. */
    public static function unknownPlaceholders(string $template): array
    {
        preg_match_all('/\{[^}]*\}/', $template, $m);

        return array_values(array_diff(array_unique($m[0]), self::PLACEHOLDERS));
    }

    public static function template(Client $client): string
    {
        return filled($client->pending_path_template) ? trim($client->pending_path_template) : self::DEFAULT_TEMPLATE;
    }

    public static function prefix(Client $client): string
    {
        return filled($client->folder_prefix) ? trim($client->folder_prefix) : trim((string) $client->name);
    }

    public static function pendingPath(string $template, Carbon $date): string
    {
        return strtr($template, [
            '{month_short}' => $date->format('M'),
            '{MONTH_SHORT}' => strtoupper($date->format('M')),
            '{month}' => $date->format('F'),
            '{year}' => $date->format('Y'),
            '{mm}' => $date->format('m'),
        ]);
    }
}
