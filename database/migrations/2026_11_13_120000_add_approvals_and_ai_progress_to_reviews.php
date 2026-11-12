<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Review sign-off by named people: every required approver has to OK a cut
 * before it is approved, and only an approved cut can be made Video ready.
 * Plus where Gemini's check is up to, so the page can show progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_settings', function (Blueprint $table) {
            $table->id();
            // The people who must all approve; empty = any one reviewer.
            $table->json('approver_ids')->nullable();
            // Video ready only after the cut is approved.
            $table->boolean('require_for_ready')->default(true);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Keerthivasan and Annamalai, as asked, when they exist.
        $ids = DB::table('users')->whereIn('name', ['Keerthivasan', 'Annamalai'])->orderBy('name', 'desc')->pluck('id')->all();
        DB::table('review_settings')->insert(['approver_ids' => json_encode($ids), 'require_for_ready' => true, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('review_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('note', 1000)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['review_video_id', 'user_id']);
        });

        Schema::table('review_videos', function (Blueprint $table) {
            // queued | uploading | processing | watching | writing
            $table->string('ai_stage', 20)->nullable()->after('ai_attempts');
            $table->unsignedTinyInteger('ai_progress')->default(0)->after('ai_stage');
            $table->timestamp('ai_stage_at')->nullable()->after('ai_progress');
        });
    }

    public function down(): void
    {
        Schema::table('review_videos', fn (Blueprint $table) => $table->dropColumn(['ai_stage', 'ai_progress', 'ai_stage_at']));
        Schema::dropIfExists('review_approvals');
        Schema::dropIfExists('review_settings');
    }
};
