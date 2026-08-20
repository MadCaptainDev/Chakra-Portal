<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Create the content accounts and venture mappings that can be established
 * with confidence, so the mapping screen opens with the obvious work
 * already done rather than thirty empty dropdowns.
 *
 * Only unambiguous pairings are seeded. Everything genuinely uncertain --
 * "PR", "LOK", "Bliss", "Mamoritai", "thinkwithpriya" and friends -- is
 * deliberately left unmapped for a person to decide on the mapping screen.
 * Guessing them here would produce a dashboard that looks authoritative and
 * is quietly wrong, which is worse than one that says "18 ventures need
 * mapping".
 *
 * Idempotent: re-running never duplicates an account or moves a venture
 * somebody has since re-assigned by hand.
 */
class SeedContentAccounts extends Command
{
    protected $signature = 'content:seed-accounts {--dry-run : Show what would be created without writing anything}';

    protected $description = 'Create content accounts and venture mappings for the pairings that are unambiguous';

    /**
     * client name => [account name => [notion venture strings]]
     *
     * Venture strings are matched case-insensitively against what is
     * actually in content_items, so the curly apostrophe in "Surya’s
     * Restaurant" and the casing of "Sva womenswear" do not have to be
     * reproduced exactly here.
     */
    private const MAP = [
        'SVA Silks and Readymades' => [
            // Two accounts, planned and targeted separately -- the reason
            // ContentAccount exists at all.
            'SVA Silks' => ['SVA Silks', 'SVA Tier 2', 'SVA MART'],
            'SVA Womenswear' => ['Sva womenswear'],
        ],
        'SVA Gold and Diamonds' => [
            'SVA Jewells' => ['SVA Jewells'],
        ],
        'Riya Makeover Artisty' => [
            'Riya' => ['Riya'],
        ],
        'Suryas Groups of Companies' => [
            'Surya’s Restaurant' => ['Surya’s Restaurant'],
        ],
        'Digital Harvest (Janet Hospitals)' => [
            'Janet' => ['Janet'],
        ],
        'Sri Azhagar Thanga Maligai' => [
            'Azhagar Thanga Maaligai' => ['Alagar Thangamaaligai'],
        ],
        'SDA Mobiles' => [
            'SDA Mobiles' => ['SDA Mobiles'],
        ],
        'DJ THANGA MAALIGAI' => [
            'DJ Thanga Maaligai' => ['DJ'],
        ],
        'Chakra Production' => [
            'Chakra' => ['CHAKRA'],
        ],
        'Amar Dental Care' => [
            'Amar Dental' => ['Amar Dental'],
        ],
        'Zira Bridal Studio' => [
            'Zira' => ['Zira'],
        ],
        'Thillai Pets Clinic' => [
            'Thillai Pets Clinic' => ['Thillai Pets Clinic', 'Thillai Pets'],
        ],
        'Thor Gym' => [
            'Thor Gym' => ['THOR'],
        ],
        'Vinu priya Personal Branding' => [
            'Vinupriya' => ['Vinupriya - Personal Branding'],
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Every venture spelling that actually exists in the synced data,
        // keyed by a folded form so the map above can be written the way a
        // person would say it.
        $actual = ContentItem::query()
            ->whereNotNull('venture')->where('venture', '!=', '')
            ->distinct()->pluck('venture')
            ->mapWithKeys(fn (string $v) => [Str::lower(trim($v)) => $v]);

        $createdAccounts = 0;
        $mappedVentures = 0;
        $missing = [];

        foreach (self::MAP as $clientName => $accounts) {
            $client = Client::where('name', $clientName)->first();

            if (! $client) {
                $this->warn("Client not found, skipped: {$clientName}");

                continue;
            }

            foreach ($accounts as $accountName => $ventures) {
                $account = ContentAccount::where('client_id', $client->id)
                    ->where('name', $accountName)
                    ->first();

                if (! $account) {
                    $this->line("  + account: {$clientName} → {$accountName}");
                    $createdAccounts++;

                    if (! $dryRun) {
                        $account = ContentAccount::create([
                            'client_id' => $client->id,
                            'name' => $accountName,
                        ]);
                    }
                }

                foreach ($ventures as $venture) {
                    $real = $actual[Str::lower(trim($venture))] ?? null;

                    if ($real === null) {
                        $missing[] = $venture;

                        continue;
                    }

                    // Never move a venture that already has a home: a person
                    // may have re-assigned it deliberately on the mapping
                    // screen, and this command must not undo that.
                    if (ContentAccountVenture::where('venture', $real)->exists()) {
                        continue;
                    }

                    $this->line("      ↳ {$real}");
                    $mappedVentures++;

                    if (! $dryRun && $account) {
                        ContentAccountVenture::create([
                            'content_account_id' => $account->id,
                            'venture' => $real,
                        ]);
                    }
                }
            }
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry run] ' : '')."{$createdAccounts} account(s), {$mappedVentures} venture(s) mapped.");

        if ($missing !== []) {
            $this->warn('Configured but not present in synced data: '.implode(', ', $missing));
        }

        $unmapped = ContentAccount::unmappedVentures();

        if ($unmapped->isNotEmpty()) {
            $this->newLine();
            $this->warn($unmapped->count().' venture(s) still unmapped, '.$unmapped->sum('items').' item(s):');

            foreach ($unmapped as $row) {
                $this->line(sprintf('  %-28s %d', $row->venture, $row->items));
            }

            $this->line('Assign these under Setup → Content Accounts.');
        }

        return self::SUCCESS;
    }
}
