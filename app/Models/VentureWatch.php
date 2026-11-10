<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One venture one person keeps an eye on from the dashboard. */
class VentureWatch extends Model
{
    protected $fillable = ['user_id', 'venture', 'sort_order'];

    protected $casts = ['low_alerted_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
