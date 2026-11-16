<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the competitor brain did and why: scripted a reel, learned, confirmed, contradicted, answered a question. */
class CompetitorBrainLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'competitor_brain_log';

    protected $fillable = ['competitor_account_id', 'competitor_reel_id', 'competitor_brain_note_id', 'user_id', 'event', 'note', 'detail'];

    public static function write(string $event, string $note, array $links = [], ?string $detail = null): self
    {
        return static::create(['event' => $event, 'note' => \Illuminate\Support\Str::limit($note, 2000), 'detail' => $detail] + $links);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CompetitorAccount::class, 'competitor_account_id');
    }

    public function reel(): BelongsTo
    {
        return $this->belongsTo(CompetitorReel::class, 'competitor_reel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
