<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn of an admin's conversation with the assistant.
 *
 * See the migration for why a tool's *output* is never stored here. What is
 * stored is replayed to the model on the next message, and a figure replayed
 * tomorrow is a figure quoted wrongly.
 */
class AdminAgentMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    /**
     * How long a thread stays one thread.
     *
     * Long enough that "and the one before that?" works, short enough that
     * tomorrow morning's "how are we doing" is not answered in the context of
     * last night's argument about one late invoice.
     */
    public const CONTEXT_HOURS = 3;

    /**
     * How many turns are replayed at most, newest kept.
     *
     * Three exchanges. This is not a guess at what reads well -- it is the
     * budget. The whole conversation is resent on every call, and on a free
     * tier metered at eight thousand tokens a minute a long memory is paid
     * for out of the same purse as the next question. Three covers "and the
     * one before that?", which is as far back as anyone reaches on a phone.
     */
    public const CONTEXT_TURNS = 6;

    protected $fillable = [
        'wa_id',
        'user_id',
        'role',
        'body',
        'tool_calls',
        'model',
        'input_tokens',
        'output_tokens',
    ];

    protected $casts = [
        'tool_calls' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * This number's live thread, oldest first, ready to hand to the model.
     *
     * Any assistant turn at the front is dropped. A window that begins with
     * the assistant is a window that happened to open mid-exchange, and the
     * Messages API requires the first message to be the user's -- it answers
     * a 400, not a best effort.
     */
    public static function transcriptFor(string $waId): array
    {
        $turns = static::query()
            ->where('wa_id', $waId)
            ->where('created_at', '>=', now()->subHours(self::CONTEXT_HOURS))
            ->latest('id')
            ->limit(self::CONTEXT_TURNS)
            ->get()
            ->sortBy('id')
            ->map(fn (self $message) => ['role' => $message->role, 'content' => $message->body])
            ->values();

        while ($turns->isNotEmpty() && $turns->first()['role'] !== self::ROLE_USER) {
            $turns->shift();
        }

        return $turns->values()->all();
    }

    public function scopeSpoken(Builder $query): void
    {
        $query->whereIn('role', [self::ROLE_USER, self::ROLE_ASSISTANT]);
    }
}
