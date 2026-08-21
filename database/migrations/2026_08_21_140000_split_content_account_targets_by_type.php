<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One target per content type, not one per account.
 *
 * A studio does not commit to "15 things a month" -- it commits to so many
 * Instagram reels, so many posts, so many YouTube shorts, and those are
 * different amounts of work. A single number could be hit while the mix was
 * entirely wrong, which is exactly the failure a target is supposed to catch.
 *
 * Column names are target_{source}, matching the keys in
 * config('notion.databases'), so ContentAccount::targetFor() is a lookup
 * rather than a translation table that has to be kept in step.
 *
 * Stories deliberately get no target: they are 67 rows against 1,723 and
 * nobody plans them by the month.
 *
 * The existing single target is copied into target_reel rather than dropped
 * -- every account carrying one had the same placeholder 15, reels are the
 * largest type by far, and silently discarding numbers somebody had just
 * typed in would be the worse of the two guesses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('target_reel')->nullable()->after('name');
            $table->unsignedSmallInteger('target_post')->nullable()->after('target_reel');
            $table->unsignedSmallInteger('target_youtube')->nullable()->after('target_post');
        });

        DB::table('content_accounts')->whereNotNull('monthly_target')
            ->update(['target_reel' => DB::raw('monthly_target')]);

        Schema::table('content_accounts', function (Blueprint $table) {
            $table->dropColumn('monthly_target');
        });
    }

    public function down(): void
    {
        Schema::table('content_accounts', function (Blueprint $table) {
            $table->unsignedSmallInteger('monthly_target')->nullable()->after('name');
        });

        DB::table('content_accounts')->whereNotNull('target_reel')
            ->update(['monthly_target' => DB::raw('target_reel')]);

        Schema::table('content_accounts', function (Blueprint $table) {
            $table->dropColumn(['target_reel', 'target_post', 'target_youtube']);
        });
    }
};
