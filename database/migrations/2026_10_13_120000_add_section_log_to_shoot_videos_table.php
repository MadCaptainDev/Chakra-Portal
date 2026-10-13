<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a video shot from a script went, section by section: each one's
     * heading, whether it was shot, how many takes and the seconds spent.
     * seconds is the sum, kept as a column so totals need no JSON.
     */
    public function up(): void
    {
        Schema::table('shoot_videos', function (Blueprint $table) {
            $table->json('section_log')->nullable()->after('notes');
            $table->unsignedInteger('seconds')->nullable()->after('section_log');
        });
    }

    public function down(): void
    {
        Schema::table('shoot_videos', function (Blueprint $table) {
            $table->dropColumn(['section_log', 'seconds']);
        });
    }
};
