<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When the reviewers were reminded that this cut is still waiting (once per version). */
    public function up(): void
    {
        Schema::table('review_videos', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('review_videos', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
