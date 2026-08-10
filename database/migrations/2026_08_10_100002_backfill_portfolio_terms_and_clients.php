<?php

use App\Models\TaxonomyTerm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Turn what people already typed into the master lists.
     *
     * Every distinct platform / format / objective already on a piece becomes
     * a term, and the piece is pointed at it. Matching is case- and
     * whitespace-insensitive, so "Instagram Reels" and "instagram reels "
     * collapse into one term rather than two -- which is the whole reason the
     * master table exists.
     *
     * Clients are matched by name, exactly and case-insensitively. A name with
     * no matching client row is left as text: guessing which client someone
     * meant is exactly the kind of "helpful" migration that quietly mis-files
     * work, so it does not guess.
     *
     * Runs only over rows that exist now. Re-running is harmless.
     */
    public function up(): void
    {
        $this->backfillTerms('platform', TaxonomyTerm::TYPE_PLATFORM, 'platform_id');
        $this->backfillTerms('format', TaxonomyTerm::TYPE_FORMAT, 'format_id');
        $this->backfillTerms('objective', TaxonomyTerm::TYPE_OBJECTIVE, 'objective_id');

        $this->backfillClients();
    }

    /**
     * Down leaves the terms in place. They are now real master data that the
     * studio may have edited, and the text columns they came from were never
     * touched, so there is nothing to restore.
     */
    public function down(): void {}

    private function backfillTerms(string $column, string $type, string $foreignKey): void
    {
        $values = DB::table('portfolio_items')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->pluck($column)
            ->all();

        // Keyed by the normalised form so casing differences fold together,
        // while the first spelling seen becomes the term's display name.
        $canonical = [];

        foreach ($values as $value) {
            $key = Str::lower(trim((string) $value));

            if ($key !== '' && ! isset($canonical[$key])) {
                $canonical[$key] = trim((string) $value);
            }
        }

        $order = 0;

        foreach ($canonical as $key => $name) {
            $term = TaxonomyTerm::firstOrCreate(
                ['type' => $type, 'slug' => TaxonomyTerm::uniqueSlug($type, $name)],
                ['name' => $name, 'sort_order' => $order++, 'is_active' => true],
            );

            DB::table('portfolio_items')
                ->whereRaw('LOWER(TRIM('.$column.')) = ?', [$key])
                ->update([$foreignKey => $term->id]);
        }
    }

    private function backfillClients(): void
    {
        $clients = DB::table('clients')->get(['id', 'name']);

        foreach ($clients as $client) {
            DB::table('portfolio_items')
                ->whereNull('client_id')
                ->whereRaw('LOWER(TRIM(client_name)) = ?', [Str::lower(trim($client->name))])
                ->update(['client_id' => $client->id]);
        }
    }
};
