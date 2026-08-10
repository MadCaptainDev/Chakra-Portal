<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point the portfolio at real records instead of typed text.
     *
     * `client_name` stays. It is not legacy baggage: a wedding filed as
     * "Private", or speculative work for a business with no CRM record yet,
     * has a client name and no client row, and forcing one would mean junk in
     * the clients table. The name is now the fallback, and the link wins when
     * both are set.
     *
     * The old platform / format / objective strings stay too, for the same
     * reason a backfill is never quite complete: anything the backfill could
     * not match still reads correctly through the model's accessors. Nothing
     * is dropped -- dropping a column is the one step that cannot be undone.
     */
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('portfolio_category_id')
                ->constrained()->nullOnDelete();

            foreach (['platform_id', 'format_id', 'objective_id', 'service_type_id'] as $column) {
                $table->foreignId($column)->nullable()
                    ->constrained('taxonomy_terms')->nullOnDelete();
            }
        });

        // Tags are the one list a piece can hold several of.
        Schema::create('portfolio_item_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('taxonomy_term_id')->constrained()->cascadeOnDelete();

            $table->unique(['portfolio_item_id', 'taxonomy_term_id']);
        });

        // Sector lives on the client, not on each piece of work.
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('industry_id')->nullable()
                ->constrained('taxonomy_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('industry_id');
        });

        Schema::dropIfExists('portfolio_item_tag');

        Schema::table('portfolio_items', function (Blueprint $table) {
            foreach (['client_id', 'platform_id', 'format_id', 'objective_id', 'service_type_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
        });
    }
};
