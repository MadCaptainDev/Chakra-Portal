<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Off by default. Announcements have only ever been a staff-facing thing
 * (My\DashboardController's personal dashboard) -- exposing every one of
 * them to clients the moment this ships would hand a client whatever
 * internal note was posted for staff (a server maintenance window, a
 * policy change) with no one having decided that was theirs to see. This
 * is the one explicit opt-in per announcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->boolean('visible_to_clients')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('visible_to_clients');
        });
    }
};
