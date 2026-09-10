<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a page that used to sync here stops appearing in Notion's query
 * results (deleted, moved to trash, or superseded by a fresh duplicate
 * page rather than the original being edited in place -- the real cause
 * found in production), the row is flagged, never deleted -- see
 * ContentSyncService::markMissing(). Whatever pointed at it (a Script, a
 * linked Shoot) keeps its target; dashboards just stop counting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->timestamp('notion_missing_since')->nullable()->after('synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropColumn('notion_missing_since');
        });
    }
};
