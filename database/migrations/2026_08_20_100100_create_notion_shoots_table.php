<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A read-only mirror of Notion's "Shoots" production-scheduling database.
 *
 * Deliberately its own table, not a source on content_items and not the
 * portal's own `shoots` table (App\Models\Shoot): the schema shares almost
 * nothing with either -- no editor/tier/post_type here, no crew/kit
 * relations there -- and `shoots` is a first-class, writable portal record
 * that this sync must never be able to touch. See NotionShoot's own
 * docblock for the naming-collision warning.
 *
 * `client` is stored as the raw Notion select text (e.g. "SVA", "Thillai Pet
 * Clinic") rather than resolved to a client_id at sync time -- resolving now
 * and freezing the result would go stale the moment a client is renamed;
 * ContentItem::venture already made this same call for the same reason
 * (see App\Support\TimesheetVenture::normalize(), read at display time
 * instead).
 *
 * `Reels` and `Shot List` (Notion relation properties) are not synced at
 * all -- a relation property carries only related-page ids, so a readable
 * title would cost one extra API call per related row per sync, for a label
 * nothing on the board needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notion_shoots', function (Blueprint $table) {
            $table->id();
            $table->string('notion_page_id')->unique();
            $table->string('notion_url')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->string('client')->nullable();
            $table->string('team')->nullable();
            $table->string('host_model')->nullable();
            $table->string('location')->nullable();
            $table->date('shoot_date')->nullable();
            $table->decimal('duration', 6, 2)->nullable();
            // string, not integer: Notion's real values include ranges like
            // "5-6", not just whole numbers.
            $table->string('video_count')->nullable();
            $table->text('gear_needed')->nullable();
            $table->text('weather_forecast')->nullable();
            $table->string('photo_url', 1024)->nullable();
            $table->timestamp('notion_created_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('client');
            $table->index('shoot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notion_shoots');
    }
};
