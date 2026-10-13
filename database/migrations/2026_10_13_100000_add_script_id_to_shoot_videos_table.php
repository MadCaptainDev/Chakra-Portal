<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which script a video was shot from, picked on set in the shoot runner. */
    public function up(): void
    {
        Schema::table('shoot_videos', function (Blueprint $table) {
            $table->foreignId('script_id')->nullable()->after('shoot_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shoot_videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('script_id');
        });
    }
};
