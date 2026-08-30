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
        // Explicit FK: the default ("whatsapp_conversation_id") does not
        // match the migration's "conversation_id" column -- same fix as
        // WhatsappCampaign::logs() needed for the same reason.
        return $this->hasMany(WhatsappConversationNote::class, 'conversation_id');
    }

    public function labels(): BelongsToMany
    {
        // Same explicit-FK fix as notes() above -- the pivot's columns are
        // conversation_id/label_id, not the whatsapp_-prefixed defaults
        // Eloquent would otherwise guess from the class names.
        return $this->belongsToMany(WhatsappLabel::class, 'whatsapp_conversation_label', 'conversation_id', 'label_id');
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
