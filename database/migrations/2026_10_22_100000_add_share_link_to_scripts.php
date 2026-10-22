<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A script's live link for the shoot (set/{token}): anyone holding it reads
 * and edits the script with no login. last_edited_by_guest names whoever
 * typed there, since they have no user row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->string('share_token', 48)->nullable()->unique()->after('shot_at');
            $table->timestamp('share_issued_at')->nullable()->after('share_token');
            $table->string('last_edited_by_guest', 80)->nullable()->after('last_edited_at');
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['share_token', 'share_issued_at', 'last_edited_by_guest']);
        });
    }
};
