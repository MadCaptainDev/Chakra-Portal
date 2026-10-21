<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marked shot without a video on record -- scripts shot before the shoot
     * runner linked videos to scripts. A linked ShootVideo also counts as
     * shot; this is only for the ones that have none.
     */
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->timestamp('shot_at')->nullable()->after('sent_to_client_at');
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropColumn('shot_at');
        });
    }
};
