<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links content_items to the notion_shoots row that produced them, via
 * Notion's own "Shoot" relation on the Reel Planner database -- confirmed
 * live to be a single-valued relation (0 or 1 related shoot per reel), and,
 * as of this migration, the ONLY relation property that exists on any of
 * the four content-source databases (youtube/post/story carry none).
 *
 * Two columns, not one, because content items and shoots sync as separate
 * passes with no ordering guarantee within a run:
 *
 * - `notion_shoot_page_id` holds the raw Notion page id read straight off
 *   the relation property -- always written when Notion has a value, even
 *   if the matching notion_shoots row hasn't synced yet this run.
 * - `notion_shoot_id` is the resolved local FK, filled in by
 *   ContentSyncService::resolveShootLinks() once the shoot side exists.
 *   Everything that reads "which shoot made this" should use this column,
 *   not the raw id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('notion_shoot_page_id')->nullable()->after('venture')->index();
            $table->foreignId('notion_shoot_id')->nullable()->after('notion_shoot_page_id')
                ->constrained('notion_shoots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notion_shoot_id');
            $table->dropColumn('notion_shoot_page_id');
        });
    }
};
