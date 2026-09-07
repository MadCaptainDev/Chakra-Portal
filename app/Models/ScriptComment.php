<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptComment extends Model
{
    protected $fillable = [
        'script_id',
        'user_id',
        'body',
    ];

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Plain text only -- a comment is a note, not a place to paste markup
     * the way a script's own body (ScriptSection, rich text via
     * App\Support\Html's allowlist) is.
     */
    public function setBodyAttribute(?string $value): void
    {
        $this->attributes['body'] = trim(strip_tags((string) $value));
    }
}
