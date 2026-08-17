<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often "Sync now" is allowed to actually call Instagram.
 *
 * There was no throttle at all: the button posted straight to the sync
 * service on every click, so somebody double-clicking it, or refreshing a
 * slow page and pressing it again, fires the same batch of Instagram calls
 * twice in a row for no benefit -- the data cannot have changed in the
 * seconds between clicks. A studio running this against several clients'
 * accounts could burn through Meta's rate limit on repeat clicks alone.
 *
 * One number, in the database next to the rest of the Instagram settings, so
 * it is a thing staff can see and change rather than a constant buried in a
 * controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sync_throttle_minutes')->default(15)->after('verify_token');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_settings', function (Blueprint $table) {
            $table->dropColumn('sync_throttle_minutes');
        });
    }
};
