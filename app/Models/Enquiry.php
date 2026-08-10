<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enquiry extends Model
{
    use HasFactory;

    /**
     * The pages that can send someone to the enquiry form.
     *
     * A closed set on purpose: the value arrives on the query string, where a
     * visitor can put anything, so it is matched against this list and dropped
     * if it is not one of them. Nothing here identifies a person -- it records
     * which screen did the persuading.
     */
    public const SOURCES = [
        'landing' => 'Landing page',
        'portfolio' => 'Portfolio grid',
        'case-study' => 'Case study',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'project',
        'message',
        'source',
        'prompted_by',
        'ip_address',
        'read_at',
        'handled_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'handled_at' => 'datetime',
    ];

    /**
     * A readable name for the page that sent them. Older rows predate the
     * field, so an unknown or missing source reads as such rather than
     * pretending the lead came from the landing page.
     */
    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? 'Not recorded';
    }

    /**
     * True once we know which page sent them -- the inbox only shows the chip
     * when there is something to show.
     */
    public function hasSource(): bool
    {
        return isset(self::SOURCES[$this->source]);
    }

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('handled_at');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function isHandled(): bool
    {
        return $this->handled_at !== null;
    }

    /**
     * Opening an enquiry marks it read. Idempotent so re-reading a thread
     * does not churn the row.
     */
    public function markRead(): void
    {
        if ($this->isUnread()) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    /**
     * Badge count for the sidebar. Cheap enough to call on every page.
     */
    public static function unreadCount(): int
    {
        return static::unread()->count();
    }

    /**
     * State for the badge component: needs a reply, or dealt with.
     */
    public function displayStatus(): string
    {
        return match (true) {
            $this->isHandled() => 'handled',
            $this->isUnread() => 'unread',
            default => 'open',
        };
    }
}
