<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captions written for planner videos: the options Gemini gave, the note
 * and language they were asked with, and the one chosen. One row per
 * generation, so asking again with the same note reuses the last answer
 * instead of paying for it twice. See App\Services\Ai\CaptionWriter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_captions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 20)->default('english');
            $table->text('note')->nullable();
            $table->json('options');
            // What was sent, hashed: the same ask again is answered from here.
            $table->string('input_hash', 64);
            $table->string('model', 80)->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['content_item_id', 'input_hash']);
        });

        Schema::table('content_items', function (Blueprint $table) {
            // The caption chosen for this video, edited or not. Portal-owned:
            // the Notion planners have no caption property.
            $table->text('caption')->nullable();
            $table->timestamp('caption_at')->nullable();
            $table->foreignId('caption_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caption_by_id');
            $table->dropColumn(['caption', 'caption_at']);
        });
        Schema::dropIfExists('content_captions');
    }
};
