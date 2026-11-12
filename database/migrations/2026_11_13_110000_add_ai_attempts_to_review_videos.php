<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** How many times the AI check has been tried, so a busy Gemini is retried a few times, not forever. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_videos', fn (Blueprint $table) => $table->unsignedTinyInteger('ai_attempts')->default(0)->after('ai_status'));
    }

    public function down(): void
    {
        Schema::table('review_videos', fn (Blueprint $table) => $table->dropColumn('ai_attempts'));
    }
};
