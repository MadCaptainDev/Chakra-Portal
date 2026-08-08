<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'project',
        'message',
        'ip_address',
        'read_at',
        'handled_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'handled_at' => 'datetime',
    ];

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
