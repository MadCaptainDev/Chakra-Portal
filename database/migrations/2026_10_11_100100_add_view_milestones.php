<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Views milestones: "this reel just crossed 50K views" posted as an
 * announcement by App\Services\ViewMilestones.
 *
 * announcements gains a kind (null = written by a person, 'milestone' =
 * posted by the portal), a link to the reel, and an end date so news like
 * this drops off the dashboards after a week instead of piling up.
 * social_media_items remembers the highest milestone already announced, so
 * each reel announces each step once and never a lower one later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('body');
            $table->string('link_url', 500)->nullable()->after('kind');
            $table->timestamp('expires_at')->nullable()->after('visible_to_clients');
        });

        Schema::table('social_media_items', function (Blueprint $table) {
            $table->unsignedInteger('views_milestone')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn(['kind', 'link_url', 'expires_at']);
        });

        Schema::table('social_media_items', function (Blueprint $table) {
            $table->dropColumn('views_milestone');
        });
    }
};
