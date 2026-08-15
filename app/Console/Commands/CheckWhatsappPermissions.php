<?php

namespace App\Console\Commands;

use App\Models\WhatsappSetting;
use App\Services\WhatsappGraph;
use Illuminate\Console\Command;
use Throwable;

/**
 * Make one real API call per permission, so Meta's App Review checklist can
 * see them being used.
 *
 * Meta will not grant a permission until it has watched the app exercise it at
 * least once -- the "0 of 1 API call(s) required" line on the review screen is
 * literally a counter. This walks the list and makes the cheapest read-only
 * call that each permission gates, then reports what Meta said.
 *
 * Read-only by design. Nothing here creates, sends or changes anything: it
 * exists to be run repeatedly against a live account without consequence.
 */
class CheckWhatsappPermissions extends Command
{
    protected $signature = 'whatsapp:check-permissions';

    protected $description = 'Exercise each Meta permission once, for App Review';

    public function handle(): int
    {
        $settings = WhatsappSetting::current();
        $graph = new WhatsappGraph($settings);

        if (! $graph->isConfigured()) {
            $this->error('No access token. Save one in Settings -> WhatsApp first.');

            return self::FAILURE;
        }

        $waba = $settings->business_account_id;
        $phone = $settings->phone_number_id;

        $results = [];

        /*
         * public_profile. The cheapest call Meta offers, and the one that
         * confirms the token is alive at all -- if this fails, nothing below is
         * worth reading, because the problem is the token rather than any
         * permission.
         */
        $results[] = $this->attempt('public_profile', 'GET /me', fn () => $graph->get('me', ['fields' => 'id,name']));

        if ($waba) {
            /*
             * whatsapp_business_management. Two calls rather than one: listing
             * the numbers on the account and listing its templates are the two
             * things this permission is actually for, and a reviewer looking at
             * the counter sees it used the way it will be used.
             */
            $results[] = $this->attempt(
                'whatsapp_business_management',
                "GET /{$waba}/phone_numbers",
                fn () => $graph->get("{$waba}/phone_numbers", ['fields' => 'id,display_phone_number,verified_name,quality_rating'])
            );

            $results[] = $this->attempt(
                'whatsapp_business_management',
                "GET /{$waba}/message_templates",
                fn () => $graph->get("{$waba}/message_templates", ['fields' => 'name,status,language', 'limit' => 10])
            );

            /*
             * business_management. Reached through the WABA's owning business
             * rather than by asking for a business ID we do not have: the WABA
             * knows who owns it, and reading that business is exactly what the
             * permission gates.
             */
            $results[] = $this->attempt(
                'business_management',
                "GET /{$waba}?fields=owner_business_info",
                fn () => $graph->get($waba, ['fields' => 'id,name,owner_business_info'])
            );
        } else {
            $this->warn('No WhatsApp business account ID saved — skipping the two management permissions.');
        }

        if ($phone) {
            // whatsapp_business_messaging is already granted by the send, but
            // reading the number back proves the pairing of token and number.
            $results[] = $this->attempt(
                'whatsapp_business_messaging',
                "GET /{$phone}",
                fn () => $graph->get($phone, ['fields' => 'id,display_phone_number,verified_name'])
            );
        }

        $this->newLine();
        $this->table(['Permission', 'Call', 'Result'], $results);

        $failed = collect($results)->filter(fn (array $row) => str_starts_with($row[2], 'FAILED'))->count();

        if ($failed > 0) {
            $this->newLine();
            $this->warn("{$failed} call(s) failed. Meta's own message is printed above and is usually the whole fix.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('All calls succeeded. Meta\'s App Review counters update within a few minutes.');

        return self::SUCCESS;
    }

    /**
     * @param  callable(): array<string, mixed>  $call
     * @return array{0: string, 1: string, 2: string}
     */
    private function attempt(string $permission, string $label, callable $call): array
    {
        $this->line("→ {$label}");

        try {
            $data = $call();
        } catch (Throwable $e) {
            $this->error('  '.$e->getMessage());

            return [$permission, $label, 'FAILED — '.$e->getMessage()];
        }

        // A short, honest summary of what came back, so a success is visibly a
        // success rather than a silent tick.
        $summary = isset($data['data'])
            ? count($data['data']).' row(s)'
            : collect($data)->except('owner_business_info')->take(2)
                ->map(fn ($value, $key) => "{$key}=".(is_scalar($value) ? $value : json_encode($value)))
                ->implode(', ');

        $this->info('  OK — '.$summary);

        return [$permission, $label, 'OK'];
    }
}
