<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reviewing together: replies to a comment, likes ("agree") on comments,
 * and who is watching the cut right now and where they are in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_comments', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('review_video_id')->constrained('review_comments')->cascadeOnDelete();
        });

        Schema::create('review_comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['review_comment_id', 'user_id']);
        });

        Schema::create('review_presence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('at_seconds', 8, 2)->nullable();
            $table->boolean('playing')->default(false);
            $table->timestamp('seen_at')->nullable();

            $table->unique(['review_video_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_presence');
        Schema::dropIfExists('review_comment_likes');
        Schema::table('review_comments', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_id'));
    }
};
