<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The competitor "brain": every reel's script and what drives its likes and
 * shares, plus a memory of learnings that grows (and corrects itself) with
 * each reel, and a log of how it learned.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What Apify already sends that we threw away.
        Schema::table('competitor_reels', function (Blueprint $table) {
            $table->string('short_code', 40)->nullable()->after('platform_post_id');
            $table->float('duration_seconds')->nullable()->after('comment_count');
            $table->json('hashtags')->nullable()->after('caption');
            $table->json('music')->nullable()->after('hashtags');
            $table->json('comments')->nullable()->after('music'); // latest comments: text, likes
        });

        // The script and the engagement read, in one Gemini pass per reel.
        Schema::table('competitor_reel_analyses', function (Blueprint $table) {
            $table->string('language', 40)->nullable()->after('breakdown');
            $table->json('transcript')->nullable()->after('language'); // [{at, said, english, on_screen}]
            $table->text('hook')->nullable()->after('transcript');
            $table->string('format', 160)->nullable()->after('hook');
            $table->string('topic', 160)->nullable()->after('format');
            $table->json('like_moments')->nullable()->after('topic');  // [{at, what, why}]
            $table->json('share_moments')->nullable()->after('like_moments');
            $table->json('comment_read')->nullable()->after('share_moments'); // themes, tags-a-friend count
            $table->text('summary')->nullable()->after('comment_read');
            $table->timestamp('learned_at')->nullable()->after('analyzed_at'); // fed to the brain
        });

        Schema::create('competitor_brain_notes', function (Blueprint $table) {
            $table->id();
            // Null = true across competitors.
            $table->foreignId('competitor_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 30); // hook, format, topic, like, share, audience, style, cta, avoid
            $table->text('insight');
            $table->json('evidence')->nullable(); // competitor_reel ids
            $table->integer('strength')->default(1); // +1 each confirming reel, -1 each contradicting one
            $table->string('status', 12)->default('active'); // active, retired
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'category']);
        });

        Schema::create('competitor_brain_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('competitor_reel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('competitor_brain_note_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 20); // scripted, learned, confirmed, contradicted, retired, asked, failed
            $table->text('note');
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
        });

        Schema::table('competitor_accounts', function (Blueprint $table) {
            $table->timestamp('learning_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('competitor_accounts', fn (Blueprint $t) => $t->dropColumn('learning_requested_at'));
        Schema::dropIfExists('competitor_brain_log');
        Schema::dropIfExists('competitor_brain_notes');
        Schema::table('competitor_reel_analyses', fn (Blueprint $t) => $t->dropColumn(['language', 'transcript', 'hook', 'format', 'topic', 'like_moments', 'share_moments', 'comment_read', 'summary', 'learned_at']));
        Schema::table('competitor_reels', fn (Blueprint $t) => $t->dropColumn(['short_code', 'duration_seconds', 'hashtags', 'music', 'comments']));
    }
};
