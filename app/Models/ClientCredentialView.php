<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody read a client's password, and this is the note of it.
 *
 * The reason shared credentials are defensible at all. Without it, "who had
 * the Instagram login in March" has no answer, and the day an account is
 * posted to by the wrong person there is nothing to go on.
 *
 * No updated_at: a view is a thing that happened, not a record that changes.
 */
class ClientCredentialView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_credential_id',
        'user_id',
        'ip_address',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(ClientCredential::class, 'client_credential_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
