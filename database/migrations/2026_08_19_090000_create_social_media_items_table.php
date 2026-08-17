<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One post/reel/carousel cached from a connected social account.
 *
 * A local copy so the content-performance table does not mean one API round
 * trip per page load, and so a media item that Instagram later deletes still
 * has a row here with whatever insights were captured against it -- the
 * studio's own record of what a piece did, not Instagram's to take back.
 *
 * `social` prefix and platform-agnostic shape, matching social_accounts: the
 * day a YouTube video is cached it is a row here too, not a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();

            $table->string('platform_media_id');

            // IMAGE | VIDEO | CAROUSEL_ALBUM
            $table->string('media_type', 30)->nullable();
            // FEED | REELS | STORY -- what metrics are even askable depends on
            // this, so it is read once here rather than re-derived per sync.
            $table->string('media_product_type', 30)->nullable();

            $table->text('caption')->nullable();
            // Instagram CDN URLs are signed and long -- see the migration that
            // widened profile_picture_url after the first live connection hit
            // exactly this. TEXT from the start here.
            $table->text('permalink')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('media_url')->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cached_at')->nullable();

            $table->timestamps();

            $table->unique(['social_account_id', 'platform_media_id']);
            // The only two reads: this account's items, newest first.
            $table->index(['social_account_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_items');
    }
};
