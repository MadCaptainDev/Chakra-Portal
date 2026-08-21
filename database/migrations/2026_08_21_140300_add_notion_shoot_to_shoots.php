<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Notion shoot a portal shoot was imported from, if any.
 *
 * Unique, so one Notion shoot can never be imported twice -- re-running the
 * import updates the existing portal shoot instead of growing a duplicate
 * every time somebody presses the button.
 *
 * Null means the shoot was created in the portal and does not exist in
 * Notion. That is a real and expected state, not an error: the integration
 * token is read-only, so nothing the portal creates can be pushed back, and
 * the Shoots screen says so rather than implying Notion knows about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->foreignId('notion_shoot_id')->nullable()->unique()->after('client_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notion_shoot_id');
        });
    }
};
