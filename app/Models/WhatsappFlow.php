<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A flow definition: the node graph FlowEngine walks, plus what starts it.
 *
 * `graph` is the whole visual flow -- a `start_node_id` and a `nodes` map
 * keyed by node id, each entry carrying its `type` and whatever that node
 * type needs (see App\Services\WhatsappFlow\FlowEngine for the shape). It
 * may also carry an optional `limits` map (`max_iterations`,
 * `max_node_visits`, `max_execution_seconds`) that overrides the engine's
 * defaults for this flow alone -- how a test keeps a deliberately cyclic
 * graph from running anywhere near the real caps.
 */
class WhatsappFlow extends Model
{
    protected $fillable = [
        'name',
        'trigger_type',
        'trigger_config',
        'graph',
        'is_active',
        'version',
        'created_by_id',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'graph' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    /**
     * Foreign key given explicitly: Eloquent's own default guess for a
     * hasMany() is `{snake(class_basename($this))}_id` --
     * `whatsapp_flow_id` for this model -- but the actual column
     * (see the whatsapp_flow_sessions migration) is `flow_id`. Left
     * un-guessed, `$flow->sessions`/`withCount('sessions')` fails outright
     * (querying a column that does not exist) rather than silently
     * returning the wrong rows -- caught by Task 10's own flows.index test,
     * fixed here as a one-line, pre-existing bug in this relation.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(WhatsappFlowSession::class, 'flow_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
