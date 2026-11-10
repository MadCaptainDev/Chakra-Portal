<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small local picture of each published video, taken from its Instagram
 * post -- Instagram's own thumbnail links expire within days, so a link is
 * not something to keep. See App\Services\Videos\VideoThumbnail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Relative to public/, e.g. uploads/video-thumbs/412-9f2c.webp
            $table->string('thumbnail_path')->nullable();
            $table->timestamp('thumbnail_saved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('videos', fn (Blueprint $table) => $table->dropColumn(['thumbnail_path', 'thumbnail_saved_at']));
    }
};
