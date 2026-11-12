<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One set of opening lines suggested for a script. See App\Services\Ai\HookWriter. */
class ScriptHook extends Model
{
    protected $fillable = ['script_id', 'user_id', 'language', 'note', 'hooks', 'input_hash', 'model', 'cost_usd'];

    protected $casts = ['hooks' => 'array', 'cost_usd' => 'float'];

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }
}
