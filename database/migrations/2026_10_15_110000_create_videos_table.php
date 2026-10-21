<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per video. It holds links and the portal's own fact
        // (posted_as) only: title, status, dates and the script text stay in
        // the Notion copy and are read live, so a change in Notion shows up
        // here without anything being copied twice.
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('script_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shoot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shoot_video_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('todo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('social_media_item_id')->nullable()->constrained()->nullOnDelete();
            // Reserved for the YouTube Shorts sync; no table to point at yet.
            $table->unsignedBigInteger('short_video_id')->nullable();
            // Where it really went: reel | post | story | delivered. Null until known.
            $table->string('posted_as', 20)->nullable()->index();
            $table->string('source', 20)->default('notion');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
