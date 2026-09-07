<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'social_media_item_id',
        'title',
        'venture',
        'notion_shoot_page_id',
        'notion_shoot_id',
        'status',
        'published_date',
        'shoot_date',
        'editor',
        'tier',
        'post_type',
        'ssd',
        'assigned_to',
        'assigned_user_id',
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

    /**
     * The real Instagram post this planned item turned into, if one was
     * matched -- see InstagramContentMatcher. Null is ordinary: only three
     * clients have Instagram connected, and nothing posted before that
     * connection exists locally to match against.
     */
    public function socialMediaItem(): BelongsTo
    {
        return $this->belongsTo(SocialMediaItem::class);
    }

    /**
     * The shoot that produced this item, resolved from Notion's own
     * Reel<->Shoot relation (see ContentSyncService::resolveShootLinks()).
     * Null either means Notion has no relation set for this reel yet, or
     * this content source doesn't carry the relation at all (only Reel
     * does, as of this being added).
     */
    public function notionShoot(): BelongsTo
    {
        return $this->belongsTo(NotionShoot::class);
    }

    /**
     * Who the portal says this is assigned to -- distinct from
     * `assigned_to`, a free-text Notion field the sync overwrites every
     * run. This column is portal-owned: a person sets it, and only
     * ContentSyncService::resolveAssignments() ever fills it automatically,
     * and only while it's still empty. See that method's doc block.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * "Shot on 5 Sep" if the linked shoot has a date, otherwise just its
     * title -- what a board card has room to show. Null, not an empty
     * string, when there is nothing linked, so a view can `@if` on it
     * rather than printing a blank line.
     */
    public function linkedShootLabel(): ?string
    {
        if (! $this->relationLoaded('notionShoot') && $this->notion_shoot_id === null) {
            return null;
        }

        $shoot = $this->notionShoot;

        if (! $shoot) {
            return null;
        }

        return $shoot->shoot_date
            ? 'Shot '.$shoot->shoot_date->format('j M')
            : $shoot->title;
    }

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
