<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reel's YouTube Short is the same video without the copyrighted music
 * -- not counted as content, but posting it is a duty. Whoever posts it
 * marks it here; the daily summary lists reels out on Instagram without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->timestamp('youtube_posted_at')->nullable();
            $table->foreignId('youtube_posted_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('youtube_posted_by_id');
            $table->dropColumn('youtube_posted_at');
        });
    }
};
