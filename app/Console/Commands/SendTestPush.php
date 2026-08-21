<?php

namespace App\Console\Commands;

use App\Models\PushSetting;
use App\Models\User;
use App\Services\Push\PushMessage;
use App\Services\Push\PushSender;
use Illuminate\Console\Command;
use Throwable;

/**
 * Send one push notification from the command line.
 *
 * Proves the server half of the FCM integration works -- the OAuth
 * exchange, the service account, the project id -- without a browser or an
 * opted-in device in the way. Run it against somebody who HAS opted in and
 * watch the notification land; when something is wrong it is Google's own
 * error that gets printed, which is almost always the whole diagnosis.
 */
class SendTestPush extends Command
{
    protected $signature = 'push:send
        {user? : Email or id of who to send to. Omitted = every staff member with a registered device}
        {--title=Test notification : Notification title}
        {--body=Sent from push:send. : Notification body}';

    protected $description = 'Send a push notification through Firebase Cloud Messaging';

    public function handle(): int
    {
        if (! PushSetting::current()->canSend()) {
            $this->error('Not configured. Add a Firebase service account under Setup -> Notifications.');

            return self::FAILURE;
        }

        $identifier = $this->argument('user');

        $users = $identifier
            ? User::query()->where('email', $identifier)->orWhere('id', $identifier)->get()
            : User::staff()->whereHas('pushTokens')->get();

        if ($users->isEmpty()) {
            $this->error($identifier
                ? "No user found matching \"{$identifier}\"."
                : 'No staff member has a registered device yet.');

            return self::FAILURE;
        }

        $message = new PushMessage(
            title: (string) $this->option('title'),
            body: (string) $this->option('body'),
        );

        foreach ($users as $user) {
            $tokens = $user->routeNotificationForFcm();

            if ($tokens->isEmpty()) {
                $this->warn("{$user->email}: no registered device, skipped.");

                continue;
            }

            $this->line("Sending to <options=bold>{$user->email}</> ({$tokens->count()} device(s))");

            try {
                $result = PushSender::make()->send($tokens, $message);
            } catch (Throwable $e) {
                // Google's message verbatim -- almost always names its own
                // fix (a malformed service account, a project mismatch).
                $this->error('  '.$e->getMessage());

                return self::FAILURE;
            }

            $this->info("  sent={$result['sent']} pruned={$result['pruned']} failed={$result['failed']}");
        }

        return self::SUCCESS;
    }
}
