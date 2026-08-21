<?php

namespace App\Models;

use App\Support\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser that has agreed to receive push notifications.
 *
 * See the migration for why the plaintext token is stored (safe -- it names
 * a device inside our own Firebase project) and why token_hash carries the
 * unique index (text has none).
 */
class PushToken extends Model
{
    /*
     * token_hash is derived, never posted. last_used_at / last_failed_at /
     * failure_reason are stamped by PushSender after a real send attempt,
     * never accepted from a request.
     */
    protected $fillable = [
        'user_id',
        'token',
        'token_hash',
        'device_label',
        'device_kind',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Register a token for this user and this browser, moving it here if it
     * already belonged to somebody else.
     *
     * ONE browser profile has ONE FCM token. If Aron signs out on the studio
     * iMac and Meera signs in and opts in, the SAME token comes back --
     * Firebase does not know a person changed, only that the same browser
     * asked again. updateOrCreate() on token_hash reassigns user_id rather
     * than refusing or leaving the old owner in place: keeping Aron as the
     * owner would mean Meera's rejected timesheet day gets pushed to a
     * device now showing Aron's name. This is the single most important
     * line in this class.
     */
    public static function register(User $user, string $token, ?string $userAgent): self
    {
        $device = Device::describe($userAgent);

        return self::updateOrCreate(
            ['token_hash' => hash('sha256', $token)],
            [
                'user_id' => $user->id,
                'token' => $token,
                'device_label' => $device['label'],
                'device_kind' => $device['kind'],
                // A re-registration (the deferred silent refresh, or simply
                // opting in again) is itself evidence the device is alive --
                // clear any stale failure rather than leaving a red line
                // under a device that just proved it works.
                'last_failed_at' => null,
                'failure_reason' => null,
            ],
        );
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    public function markFailed(string $reason): void
    {
        $this->forceFill(['last_failed_at' => now(), 'failure_reason' => $reason])->save();
    }
}
