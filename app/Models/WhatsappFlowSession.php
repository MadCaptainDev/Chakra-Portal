<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-progress (or finished) walk through a WhatsappFlow's graph for one
 * WhatsApp number. See the migration for the field-by-field rationale.
 */
class WhatsappFlowSession extends Model
{
    protected $fillable = [
        'flow_id',
        'wa_id',
        'current_node_id',
        'variables',
        'status',
        'last_error',
        'iteration_count',
        'started_at',
        'last_advanced_at',
        'expires_at',
    ];

    protected $casts = [
        'variables' => 'array',
        'iteration_count' => 'integer',
        'started_at' => 'datetime',
        'last_advanced_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(WhatsappFlow::class);
    }
}
