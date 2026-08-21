<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * The studio's Firebase credentials. One row, like instagram_settings /
 * whatsapp_settings / notion_settings.
 *
 * See the migration for why only service_account_json is encrypted while
 * web_config and vapid_public_key are not -- they are shipped to every
 * browser that loads the opt-in screen; that is what they are for.
 */
class PushSetting extends Model
{
    protected $fillable = [
        'service_account_json',
        'web_config',
        'vapid_public_key',
        'updated_by_id',
    ];

    protected $casts = [
        // Encrypted, not hashed: every send signs with the key inside it,
        // so it has to be readable back. Same trade as
        // WhatsappSetting::$access_token.
        'service_account_json' => 'encrypted',
    ];

    /**
     * The one row, created empty on first use.
     *
     * The retry covers two admins opening the settings screen at once: one
     * inserts, the other loses the primary key and simply reads what the
     * winner wrote.
     */
    public static function current(): self
    {
        if ($existing = static::query()->whereKey(1)->first()) {
            return $existing;
        }

        try {
            return static::forceCreate(['id' => 1]);
        } catch (QueryException $e) {
            return static::query()->whereKey(1)->first() ?? throw $e;
        }
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function isConfigured(): bool
    {
        return filled($this->service_account_json);
    }

    /**
     * Whether a send can even be attempted: not just "something was pasted"
     * but "the JSON parses and carries what FCM's OAuth flow needs".
     */
    public function canSend(): bool
    {
        $account = $this->serviceAccount();

        return $account !== null
            && filled($account['project_id'] ?? null)
            && filled($account['client_email'] ?? null)
            && filled($account['private_key'] ?? null);
    }

    /**
     * The decoded service account, or null if it is missing or not valid
     * JSON. Decoded fresh each call rather than memoised on the instance --
     * PushSetting is resolved fresh per request via current(), so a static
     * cache is not needed and would be one more thing for
     * tests/TestCase.php to have to know to flush.
     */
    public function serviceAccount(): ?array
    {
        if (blank($this->service_account_json)) {
            return null;
        }

        $decoded = json_decode((string) $this->service_account_json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function webConfig(): array
    {
        $decoded = json_decode((string) $this->web_config, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function projectId(): ?string
    {
        return $this->serviceAccount()['project_id'] ?? null;
    }

    /**
     * Whether the service account and the web config actually name the same
     * Firebase project. The single most common setup mistake -- pasting the
     * two halves from different projects -- and it does not fail here; it
     * fails twenty minutes later as SENDER_ID_MISMATCH on somebody's phone.
     * Checked at save time on the settings screen so it is caught at the
     * keyboard instead.
     */
    public function projectsMatch(): bool
    {
        $webProjectId = $this->webConfig()['projectId'] ?? null;

        return $webProjectId !== null && $webProjectId === $this->projectId();
    }

    public function endpoint(): ?string
    {
        $projectId = $this->projectId();

        return $projectId ? "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send" : null;
    }
}
