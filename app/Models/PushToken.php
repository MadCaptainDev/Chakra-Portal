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
        'environment',
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

        $pushToken = self::updateOrCreate(
            ['token_hash' => hash('sha256', $token)],
            [
                'user_id' => $user->id,
                'token' => $token,
                'device_label' => $device['label'],
                'device_kind' => $device['kind'],
            ],
        );

        // last_failed_at / failure_reason are deliberately NOT in $fillable
        // (see the class docblock -- they must never come from a request),
        // so updateOrCreate()'s fill() silently drops them. forceFill()
        // them separately: a re-registration (the deferred silent refresh,
        // or simply opting in again) is itself evidence the device is
        // alive -- clear any stale failure rather than leaving a red line
        // under a device that just proved it works.
        if ($pushToken->failure_reason !== null || $pushToken->last_failed_at !== null) {
            $pushToken->forceFill(['last_failed_at' => null, 'failure_reason' => null])->save();
        }

        return $pushToken;
    }

    /** device_kind values the mobile app registers with (PushToken::registerNative). */
    public const NATIVE_KINDS = ['ios_app', 'android_app'];

    /** An iPhone app token sent straight to Apple (APNs), not through Firebase. */
    public const KIND_APNS = 'ios_apns';

    public function isApns(): bool
    {
        return $this->device_kind === self::KIND_APNS;
    }

    public function isNativeApp(): bool
    {
        return in_array($this->device_kind, self::NATIVE_KINDS, true);
    }

    /**
     * The mobile app's FCM token. Same one-token-one-owner rule as
     * register(): a phone handed to a colleague moves to them.
     */
    public static function registerNative(User $user, string $token, string $platform, ?string $label): self
    {
        $pushToken = self::updateOrCreate(
            ['token_hash' => hash('sha256', $token)],
            [
                'user_id' => $user->id,
                'token' => $token,
                'device_label' => \Illuminate\Support\Str::limit($label ?: ($platform === 'ios' ? 'iPhone app' : 'Android app'), 64, ''),
                'device_kind' => $platform === 'ios' ? 'ios_app' : 'android_app',
            ],
        );

        if ($pushToken->failure_reason !== null || $pushToken->last_failed_at !== null) {
            $pushToken->forceFill(['last_failed_at' => null, 'failure_reason' => null])->save();
        }

        return $pushToken;
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
