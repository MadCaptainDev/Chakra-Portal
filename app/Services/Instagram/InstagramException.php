<?php

namespace App\Services\Instagram;

use RuntimeException;

/**
 * Something Instagram refused.
 *
 * Carries Meta's own message, because theirs name their own fix. `userMessage`
 * is what a staff member should read: for the handful of failures that are
 * genuinely confusing in Meta's wording, it says the thing a person can act
 * on instead.
 */
class InstagramException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $apiCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Whether the connection is dead rather than merely unlucky.
     *
     * A revoked or expired token needs the client to authorise again; a rate
     * limit or a 500 needs only patience. Marking the first as an outage would
     * hide a broken connection behind "try again later" for weeks.
     */
    public function isAuthFailure(): bool
    {
        // 190 is Meta's access-token error family; 10 and 200-299 are
        // permission errors, which a re-consent also fixes.
        return $this->status === 401
            || $this->apiCode === 190
            || $this->apiCode === 10
            || ($this->apiCode !== null && $this->apiCode >= 200 && $this->apiCode <= 299);
    }

    public function isRateLimit(): bool
    {
        return $this->status === 429 || in_array($this->apiCode, [4, 17, 32, 613], true);
    }

    /**
     * What to put in front of a person.
     *
     * Meta's message is kept unless it is one of the few that reads as
     * nonsense to somebody who did not write the integration.
     */
    public function userMessage(): string
    {
        if ($this->isRateLimit()) {
            return 'Instagram is rate limiting us. Nothing is broken — try again in a few minutes.';
        }

        if (str_contains(mb_strtolower($this->getMessage()), 'not an instagram business')) {
            return 'That account is not a Professional account. Instagram only lets Business or '
                .'Creator accounts connect — switch it in the Instagram app under Settings → '
                .'Account type, then try again.';
        }

        return $this->getMessage();
    }
}
