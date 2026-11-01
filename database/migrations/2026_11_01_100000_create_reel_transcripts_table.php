<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reel Transcriber: what a reel says, in its own language, in English, and
 * cleaned up in Tanglish for the script writers. Kept, so the same reel is
 * never sent to the speech model twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reel_transcripts', function (Blueprint $table) {
            $table->id();
            // The reel's Instagram code, or a hash of a direct link / upload.
            $table->string('source_key', 100)->unique();
            $table->string('source_url', 500)->nullable();
            $table->foreignId('social_media_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 40)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->mediumText('transcript')->nullable();
            $table->mediumText('english')->nullable();
            $table->mediumText('tanglish')->nullable();
            $table->string('summary', 500)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reel_transcripts');
    }
};
