<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentItem extends Model
{
    use HasFactory;

    public const SOURCE_YOUTUBE = 'youtube';

    public const SOURCE_REEL = 'reel';

    public const SOURCE_POST = 'post';

    public const SOURCE_STORY = 'story';

    protected $fillable = [
        'source',
        'notion_page_id',
        'notion_url',
        'title',
        'venture',
        'status',
        'published_date',
        'shoot_date',
        'editor',
        'tier',
        'post_type',
        'ssd',
        'assigned_to',
        'effort_hours',
        'insta_csv_link',
        'yt_csv_link',
        'script',
        'show_notes',
        'notion_created_at',
        'synced_at',
    ];

    protected $casts = [
        'published_date' => 'date',
        'shoot_date' => 'date',
        'notion_created_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function sourceLabel(): string
    {
        return config("notion.databases.{$this->source}.label") ?? ucfirst((string) $this->source);
    }

    public function sourceIcon(): string
    {
        return match ($this->source) {
            self::SOURCE_YOUTUBE => '🎬',
            self::SOURCE_REEL => '🎞️',
            self::SOURCE_POST => '🖼️',
            self::SOURCE_STORY => '📱',
            default => '📄',
        };
    }

    /**
     * Initials for the editor avatar chip on a board card.
     */
    public function editorInitials(): ?string
    {
        if (! $this->editor) {
            return null;
        }

        // Multi_select editors arrive comma-joined; the chip shows the first.
        $first = trim(explode(',', $this->editor)[0]);

        $parts = preg_split('/[\s\-\.]+/', $first, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return null;
        }

        $initials = mb_strtoupper(mb_substr($parts[0], 0, 1));

        if (count($parts) > 1) {
            $initials .= mb_strtoupper(mb_substr(end($parts), 0, 1));
        }

        return $initials;
    }

    /**
     * A deterministic palette slot so the same editor always gets the same
     * avatar colour across every card and board.
     */
    public function editorColorIndex(): int
    {
        return $this->editor ? crc32(mb_strtolower(trim($this->editor))) % 6 : 0;
    }
}
