<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Video review inside the portal: the editor uploads each version of a cut
 * to its editing card, Gemini gives it a quick check, and a reviewer leaves
 * comments pinned to moments in it, then approves it or sends it back.
 *
 * The files live in storage/app/private/reviews, are served through the
 * portal to people allowed to see them, and are deleted a week after the
 * video is posted (reviews:cleanup) -- review copies, not the masters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_videos', function (Blueprint $table) {
            $table->id();
            // The editing card (top-level Todo) this is a cut of.
            $table->foreignId('todo_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();
            $table->string('poster_path')->nullable();
            $table->string('mime', 60)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->decimal('duration', 8, 2)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            // pending | approved | changes
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            // none | queued | running | done | failed | skipped
            $table->string('ai_status', 20)->default('none');
            $table->string('ai_verdict', 20)->nullable();
            $table->json('ai_report')->nullable();
            $table->string('ai_error', 500)->nullable();
            $table->timestamp('ai_checked_at')->nullable();
            $table->timestamp('deleted_file_at')->nullable();
            $table->timestamps();

            $table->unique(['todo_id', 'version']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('review_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Seconds into the video; null for a comment on the whole cut.
            $table->decimal('at_seconds', 8, 2)->nullable();
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            // The correction it became when the cut was sent back.
            $table->foreignId('correction_todo_id')->nullable()->constrained('todos')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_comments');
        Schema::dropIfExists('review_videos');
    }
};
