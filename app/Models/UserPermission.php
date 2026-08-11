<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One granted ability on one module for one user.
 *
 * Written only through User::syncPermissions(), which validates every pair
 * against App\Support\Permission first -- so a row here is always a pair the
 * registry recognises.
 */
class UserPermission extends Model
{
    protected $fillable = [
        'user_id',
        'module',
        'ability',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
