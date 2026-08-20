<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One raw Notion venture string, assigned to one content account.
 *
 * `venture` is unique table-wide, so a video can never be counted against
 * two accounts.
 */
class ContentAccountVenture extends Model
{
    protected $fillable = [
        'content_account_id',
        'venture',
    ];

    public function contentAccount(): BelongsTo
    {
        return $this->belongsTo(ContentAccount::class);
    }
}
