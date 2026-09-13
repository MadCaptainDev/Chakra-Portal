<?php

namespace App\Console\Commands;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\AdminAgent\AdminAgent;
use App\Services\WhatsappSender;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask the assistant something from the server, without texting anybody.
 *
 * Exists because the assistant has four ways of looking broken that are not
 * bugs -- no key, the switch off, the day's quota spent, or no queue worker
 * running -- and from a phone all four look identical: silence. This reaches
 * the agent directly and prints what it would have sent, so the answer to
 * "why is it quiet" takes one command instead of an afternoon.
 *
 *   php artisan assistant:ask "anything overdue?"
 */
class AskAssistant extends Command
{
    protected $signature = 'assistant:ask {question* : What to ask} {--as= : An admin\'s email, if there is more than one}';

    protected $description = 'Ask the WhatsApp assistant a question from the server and print its answer';

    public function handle(AdminAgent $agent): int
    {
        $settings = AiSetting::current();

        $this->line('Provider: <info>'.$settings->providerName().'</info> · model: <info>'.$settings->modelName().'</info>');

        if (! $settings->hasKey()) {
            $this->error('No API key on file. Paste one at Setup → Assistant.');

            return self::FAILURE;
        }

        if (! $settings->is_active) {
            // Warned rather than refused: somebody testing before they switch
            // it on is exactly what this command is for.
            $this->warn('The assistant is switched off, so WhatsApp would not reach it. Asking anyway.');
        }

        if ($settings->atDailyLimit()) {
            $this->warn('Today\'s question ceiling is reached, so WhatsApp would fall back to the menu. Asking anyway.');
        }

        $admin = $this->admin();

        if ($admin === null) {
            $this->error($this->option('as')
                ? 'No admin found with that email.'
                : 'No admin user has a phone number on file.');

            return self::FAILURE;
        }

        $question = implode(' ', (array) $this->argument('question'));

        $this->newLine();
        $this->line('<comment>'.$admin->name.':</comment> '.$question);

        $started = microtime(true);

        try {
            /*
             * compose() rather than answer(): this prints what would be sent
             * and sends nothing. Testing the assistant should not put a
             * message on somebody's phone.
             */
            $body = $agent->compose($admin, $this->waId($admin), $question);
        } catch (Throwable $e) {
            // The provider's own words, which is the point of running this.
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($body);
        $this->newLine();
        $this->line('<info>'.round(microtime(true) - $started, 1).'s</info> — nothing was sent to WhatsApp.');

        return self::SUCCESS;
    }

    private function admin(): ?User
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->when($this->option('as'), fn ($query, $email) => $query->where('email', $email))
            // A phone number, because the transcript is keyed by wa_id: an
            // admin without one has no thread for this to continue.
            ->when(! $this->option('as'), fn ($query) => $query->whereNotNull('phone')->where('phone', '!=', ''))
            ->orderBy('id')
            ->first();
    }

    /**
     * The thread this lands in -- the admin's own, so a question asked here
     * and a question asked from their phone are one conversation.
     */
    private function waId(User $admin): string
    {
        return WhatsappSender::normalise((string) $admin->phone) ?: 'console';
    }
}
