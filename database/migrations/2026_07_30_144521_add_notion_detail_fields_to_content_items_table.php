<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Reel and Post planners carry far more than title/status/date. The
     * portal captures all of it now so later features don't need a re-sync.
     */
    public function up(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->string('tier')->nullable()->after('editor');
            $table->string('post_type')->nullable()->after('tier');
            $table->string('ssd')->nullable()->after('post_type');
            $table->string('assigned_to')->nullable()->after('ssd');
            $table->string('effort_hours')->nullable()->after('assigned_to');
            $table->string('insta_csv_link', 1024)->nullable()->after('effort_hours');
            $table->string('yt_csv_link', 1024)->nullable()->after('insta_csv_link');
            $table->text('script')->nullable()->after('yt_csv_link');
            $table->text('show_notes')->nullable()->after('script');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'tier',
                'post_type',
                'ssd',
                'assigned_to',
                'effort_hours',
                'insta_csv_link',
                'yt_csv_link',
                'script',
                'show_notes',
            ]);
        });
    }
};
