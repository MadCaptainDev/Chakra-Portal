<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A competitor's public Instagram account, tracked read-only.
 *
 * Deliberately NOT a SocialAccount row: that model assumes a genuine
 * OAuth-connected account (non-nullable client_id, access_token driving
 * scopeConnected()/isConnected()) -- a competitor is never connected, has no
 * token, and does not belong to one specific client the way a
 * social_accounts row must. A parallel, much smaller table instead of
 * bending that one to fit a shape it was not built for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('platform')->default('instagram');
            // Optional: "this is Client X's competitor" -- not required, since
            // a lot of competitor research is studio-wide rather than tied to
            // one client's brief.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            // Filled by ApifyClient::scrapeAccountStats() -- the bar isViral()
            // on CompetitorReel compares against, mirroring the reference
            // tool's own "outperforming this account's average" ranking
            // rather than a fixed view-count threshold.
            $table->text('profile_pic_url')->nullable();
            $table->unsignedInteger('followers_count')->nullable();
            $table->unsignedInteger('avg_views_30d')->nullable();

            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_accounts');
    }
};
