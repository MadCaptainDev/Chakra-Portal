<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Latest reading of one metric across posts" (view milestones, the
        // content dashboard) filters by metric first; the existing indexes
        // lead with the account or the post.
        Schema::table('social_insights', function (Blueprint $table) {
            $table->index(['metric', 'social_media_item_id', 'period_start'], 'social_insights_metric_item_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('social_insights', function (Blueprint $table) {
            $table->dropIndex('social_insights_metric_item_period_index');
        });
    }
};
