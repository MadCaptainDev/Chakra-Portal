<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One login the studio holds for a client.
 *
 * `secret` and `notes` are cast encrypted, so they are ciphertext the moment
 * they leave PHP and plaintext only while a request is holding them. Nothing
 * reads them except the reveal action, which writes down that it did.
 */
class ClientCredential extends Model
{
    public const KIND_INSTAGRAM = 'instagram';

    public const KIND_YOUTUBE = 'youtube';

    public const KIND_GOOGLE = 'google';

    public const KIND_FACEBOOK = 'facebook';

    public const KIND_OTHER = 'other';

    /** @var array<string, string> */
    public const KINDS = [
        self::KIND_INSTAGRAM => 'Instagram',
        self::KIND_YOUTUBE => 'YouTube',
        self::KIND_GOOGLE => 'Google / Gmail',
        self::KIND_FACEBOOK => 'Facebook',
        self::KIND_OTHER => 'Other',
    ];

    /*
     * created_by_id and updated_by_id are absent: they are stamped by the
     * controller from the signed-in user. Who added a credential is the sort
     * of fact a form post must not get to assert.
     */
    protected $fillable = [
        'client_id',
        'kind',
        'label',
        'username',
        'secret',
        'notes',
        'url',
    ];

    /**
     * `encrypted` is Laravel's AES-256 cast, keyed on APP_KEY.
     *
     * Deliberately not `hashed`: a hash can be checked and never read, which
     * is right for a password this app owns and useless for one somebody has
     * to type into Instagram.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'secret' => 'encrypted',
        'notes' => 'encrypted',
    ];

    /**
     * Hidden from array and JSON conversion.
     *
     * Belt and braces. Nothing intentionally serialises this model, but a
     * future ->toJson() in a debug response or a log line would otherwise
     * write every client's password into a file in plain text.
     *
     * @var list<string>
     */
    protected $hidden = ['secret', 'notes'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /** Every time somebody read the secret, newest first. */
    public function views(): HasMany
    {
        return $this->hasMany(ClientCredentialView::class)->latest('viewed_at');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    /** What to call it in a list: the label if there is one, else the kind. */
    public function displayName(): string
    {
        return filled($this->label) ? $this->label : $this->kindLabel();
    }

    public function hasSecret(): bool
    {
        return filled($this->secret);
    }
}
