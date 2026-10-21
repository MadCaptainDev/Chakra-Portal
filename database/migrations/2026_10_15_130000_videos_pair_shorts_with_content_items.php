<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The reserved short_video_id was meant for a YouTube API table. The
        // Shorts planner is already in Notion (content_items, source youtube),
        // so a reel's Short is just the other content item it pairs with.
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('short_video_id');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('short_content_item_id')->nullable()->after('social_media_item_id')
                ->constrained('content_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('short_content_item_id');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->unsignedBigInteger('short_video_id')->nullable();
        });
    }
};
