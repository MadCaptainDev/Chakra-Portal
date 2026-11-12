<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI check in steps that can be picked up again: where the upload to
 * Gemini has got to, the file Gemini holds, who is working on it now (a
 * short lease) and when it started (a check has 15 minutes in all).
 *
 * Only a cut's first version is checked by the AI now; later versions are
 * checked against the changes asked. Versions 2+ still waiting are let go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_videos', function (Blueprint $table) {
            $table->text('ai_upload_url')->nullable()->after('ai_stage_at');
            $table->unsignedBigInteger('ai_upload_offset')->default(0)->after('ai_upload_url');
            $table->json('ai_file')->nullable()->after('ai_upload_offset');
            $table->timestamp('ai_lease_until')->nullable()->after('ai_file');
            $table->timestamp('ai_started_at')->nullable()->after('ai_lease_until');
        });

        DB::table('review_videos')->where('version', '>', 1)->whereIn('ai_status', ['queued', 'running', 'failed'])
            ->update(['ai_status' => 'none', 'ai_stage' => null, 'ai_error' => null]);
    }

    public function down(): void
    {
        Schema::table('review_videos', function (Blueprint $table) {
            $table->dropColumn(['ai_upload_url', 'ai_upload_offset', 'ai_file', 'ai_lease_until', 'ai_started_at']);
        });
    }
};
