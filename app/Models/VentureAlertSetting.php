<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How one person wants to hear about the ventures they watch. */
class VentureAlertSetting extends Model
{
    protected $fillable = [
        'user_id', 'daily_summary', 'summary_at', 'low_stock',
        'notify_low', 'notify_ready', 'notify_published', 'notify_shot', 'whatsapp',
    ];

    protected $casts = [
        'daily_summary' => 'boolean',
        'notify_low' => 'boolean',
        'notify_ready' => 'boolean',
        'notify_published' => 'boolean',
        'notify_shot' => 'boolean',
        'whatsapp' => 'boolean',
        'low_stock' => 'integer',
        'summary_sent_on' => 'date',
    ];

    public static function for(User $user): self
    {
        return self::query()->firstOrCreate(['user_id' => $user->id]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toPanel(): array
    {
        return $this->only(['daily_summary', 'summary_at', 'low_stock', 'notify_low', 'notify_ready', 'notify_published', 'notify_shot', 'whatsapp']);
    }
}
