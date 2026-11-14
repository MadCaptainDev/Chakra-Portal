<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** How many times Gemini has been asked to write a voice note down (busy models are retried). */
    public function up(): void
    {
        Schema::table('review_comments', function (Blueprint $table) {
            $table->unsignedTinyInteger('voice_attempts')->default(0)->after('voice_state');
        });
    }

    public function down(): void
    {
        Schema::table('review_comments', function (Blueprint $table) {
            $table->dropColumn('voice_attempts');
        });
    }
};
