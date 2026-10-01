<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Notion name the connections screen should stop asking about. See the
 * create_notion_ignored_names_table migration.
 */
class NotionIgnoredName extends Model
{
    public const VENTURE = 'venture';

    public const SHOOT_CLIENT = 'shoot_client';

    protected $fillable = ['kind', 'name'];

    /** @return list<string> */
    public static function namesFor(string $kind): array
    {
        return self::where('kind', $kind)->pluck('name')->all();
    }
}
