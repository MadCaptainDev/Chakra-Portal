<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One content-account card pinned to one person's dashboard.
 *
 * Deliberately thin: the pin is the whole record. What the card then shows
 * is computed fresh from ContentDashboard every request, because a cached
 * count on a dashboard is a count that is wrong by the time anyone reads it.
 */
class DashboardContentWidget extends Model
{
    protected $fillable = [
        'user_id',
        'content_account_id',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contentAccount(): BelongsTo
    {
        return $this->belongsTo(ContentAccount::class);
    }
}
