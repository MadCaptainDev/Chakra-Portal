<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A portal-owned assignment, separate from `assigned_to` (free text,
 * one-way synced from Notion -- ContentSyncService::upsertPage() rewrites
 * it on every sync). Reassigning inside the portal would be silently
 * clobbered by the next sync if it wrote to that column instead, the same
 * reason NotionShootImporter never overwrites a client_id someone set by
 * hand. This column is never touched by the Notion sync except to
 * best-effort FILL it when empty (see ContentSyncService::resolveAssignments()) --
 * a manual reassignment always wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('assigned_to')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};
