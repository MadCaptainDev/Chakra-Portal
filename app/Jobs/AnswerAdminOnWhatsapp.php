<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AdminAgent\AdminAgent;
use App\Services\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One question, answered off the webhook's thread.
 *
 * Meta wants its 200 quickly and retries anything slow, eventually disabling
 * the subscription outright -- and an answer here is several seconds of model
 * calls and lookups. So the webhook stores the message, queues this, and
 * answers Meta; this does the thinking a moment later.
 *
 * That moment depends on a queue worker actually running. Without the cron
 * that runs `queue:work`, this row sits in the jobs table and the owner's
 * phone stays quiet -- which is worth knowing before blaming the assistant.
 *
 * Carries ids and a string, never models: a job is serialised to the database
 * and back, and a User in the payload is a User loaded from whenever the row
 * was written.
 */
class AnswerAdminOnWhatsapp implements ShouldQueue
{
    use Queueable;

    /**
     * Two attempts, because the first failure is usually the network or a
     * rate limit and the second usually works. Beyond that, somebody wants an
     * apology rather than a third silent try.
     */
    public int $tries = 2;

    public int $backoff = 15;

    public function __construct(
        private readonly string $waId,
        private readonly int $adminId,
        private readonly string $question,
    ) {}

    public function handle(AdminAgent $agent): void
    {
        $admin = User::query()->staff()->find($this->adminId);

        // The gate ran when the message arrived; this re-checks it, because
        // between then and now somebody's role could have changed and this
        // reads across every client's money.
        if ($admin === null || ! $admin->isAdmin()) {
            Log::warning('Admin assistant job skipped: no longer an admin.', [
                'user_id' => $this->adminId,
                'wa_id' => $this->waId,
            ]);

            return;
        }

        $agent->answer($admin, $this->waId, $this->question);
    }

    /**
     * Out of attempts. Say so on the phone that is waiting, then let the
     * failure stand in the failed_jobs table for somebody to read.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('Admin assistant could not answer.', [
            'wa_id' => $this->waId,
            'error' => $e?->getMessage(),
        ]);

        try {
            WhatsappSender::make()->sendText(
                $this->waId,
                "Sorry — I couldn't get to that just now. Type *menu* for the usual figures.",
            );
        } catch (Throwable $sendFailed) {
            // If WhatsApp itself is the thing that is down, there is nowhere
            // left to apologise to.
            Log::error('Admin assistant could not send its apology either.', [
                'wa_id' => $this->waId,
                'error' => $sendFailed->getMessage(),
            ]);
        }
    }
}
