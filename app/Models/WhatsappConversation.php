<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One inbox thread per WhatsApp number.
 *
 * This row is not written by hand -- WhatsappWebhookEventObserver keeps it in
 * step every time a message or an outgoing send lands in
 * WhatsappWebhookEvent, which is also where messages() reads the actual
 * conversation history back from. This table only ever holds the summary a
 * thread list needs (who, when, how many unread) plus the inbox's own state
 * (status, assignment, notes, labels) that the webhook log has no business
 * knowing about.
 */
class WhatsappConversation extends Model
{
    protected $fillable = [
        'wa_id',
        'contact_id',
        'unread_count',
        'last_message_at',
        'last_message_summary',
        'status',
        'assigned_to_id',
    ];

    protected $casts = [
        'unread_count' => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsappContact::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(WhatsappConversationNote::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappLabel::class, 'whatsapp_conversation_label');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * The actual conversation -- every inbound message and outgoing send
     * WhatsappWebhookEvent has logged for this wa_id, oldest first.
     *
     * Lives here rather than as a stored relation because the log is the
     * single source of truth for message content; this row only ever tracks
     * the summary.
     */
    public function messages(): Collection
    {
        return WhatsappWebhookEvent::where('wa_id', $this->wa_id)
            ->whereIn('type', [WhatsappWebhookEvent::TYPE_MESSAGE, WhatsappWebhookEvent::TYPE_OUTGOING])
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * Badge count for the sidebar. Cheap enough to call on every page.
     */
    public static function unreadCount(): int
    {
        return (int) static::sum('unread_count');
    }
}
