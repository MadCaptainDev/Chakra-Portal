<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a portfolio piece point at one cached Instagram post/reel instead of
 * everything being hand-typed -- see PortfolioItem::mapToInstagram().
 *
 * nullOnDelete(), not cascade: losing the cached post underneath (a re-sync
 * that stops returning it, the account being disconnected, a data-deletion
 * request) must unlink a portfolio piece, never delete a published case
 * study. Same reasoning already used for portfolio_category_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->foreignId('social_media_item_id')->nullable()
                ->after('client_id')->constrained()->nullOnDelete();

            // When refreshFromInstagram() last wrote the performance fields --
            // shown to staff as "Last refreshed from Instagram at X". Null
            // means never (unmapped, or mapped but not yet synced since).
            $table->timestamp('instagram_refreshed_at')->nullable()->after('social_media_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_media_item_id');
            $table->dropColumn('instagram_refreshed_at');
        });
    }
};
