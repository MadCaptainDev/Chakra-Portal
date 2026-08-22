<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One scraped post from a tracked competitor's account.
 *
 * A local copy for the same reason social_media_items is: the competitor's
 * post can vanish or the studio's own record of "this is what we saw and
 * when" should not depend on it staying up, and re-scraping every page load
 * would mean an Apify run (a real cost) on every visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_reels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_account_id')->constrained()->cascadeOnDelete();

            // Apify's own post identifier -- the dedupe key against a
            // re-scrape picking up the same reel twice.
            $table->string('platform_post_id');

            $table->text('video_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('caption')->nullable();

            $table->unsignedInteger('play_count')->nullable();
            $table->unsignedInteger('like_count')->nullable();
            $table->unsignedInteger('comment_count')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->timestamp('scraped_at');
            $table->timestamps();

            $table->unique(['competitor_account_id', 'platform_post_id']);
            // The only two reads: this account's reels newest/most-viewed first.
            $table->index(['competitor_account_id', 'play_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_reels');
    }
};
