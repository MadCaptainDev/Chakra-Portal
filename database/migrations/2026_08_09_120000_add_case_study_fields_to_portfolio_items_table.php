<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything the public case-study screen shows for one piece of work.
     *
     * All of it is nullable on purpose: the grid is full of pieces that will
     * never have numbers behind them, and the detail screen simply drops any
     * section it has no data for rather than showing empty scaffolding.
     */
    public function up(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            // What we made.
            $table->text('summary')->nullable();
            $table->string('platform')->nullable();
            $table->string('format')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('objective')->nullable();
            $table->date('published_on')->nullable();

            // How far it travelled.
            $table->unsignedBigInteger('views')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('profile_visits')->nullable();
            $table->unsignedBigInteger('enquiries')->nullable();
            $table->decimal('engagement_rate', 5, 2)->nullable();
            $table->decimal('completion_rate', 5, 2)->nullable();
            $table->decimal('watch_hours', 12, 1)->nullable();
            $table->decimal('avg_watch_seconds', 8, 1)->nullable();

            // What it did for the business.
            $table->unsignedInteger('leads')->nullable();
            $table->unsignedInteger('whatsapp_enquiries')->nullable();
            $table->unsignedInteger('calls')->nullable();
            $table->unsignedInteger('store_visits')->nullable();
            $table->unsignedInteger('orders')->nullable();
            $table->decimal('sales_amount', 14, 2)->nullable();
            $table->decimal('sales_before_amount', 14, 2)->nullable();
            $table->decimal('roi', 8, 2)->nullable();

            // The client's own average piece, so the numbers have a yardstick.
            $table->unsignedBigInteger('benchmark_views')->nullable();
            $table->unsignedBigInteger('benchmark_reach')->nullable();
            $table->unsignedBigInteger('benchmark_engagements')->nullable();
            $table->unsignedBigInteger('benchmark_enquiries')->nullable();
            $table->decimal('benchmark_sales_amount', 14, 2)->nullable();

            // The creative thinking, one line each.
            $table->text('creative_hook')->nullable();
            $table->text('creative_concept')->nullable();
            $table->text('creative_storytelling')->nullable();
            $table->text('creative_cta')->nullable();
            $table->text('creative_offer')->nullable();
            $table->text('creative_audience')->nullable();

            // Free-text before/after rows: [{label, before, after}, ...]. Kept
            // as text because "240K → 2.1M" is a sentence, not an arithmetic.
            $table->json('before_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_items', function (Blueprint $table) {
            $table->dropColumn([
                'summary', 'platform', 'format', 'duration_seconds', 'objective', 'published_on',
                'views', 'reach', 'likes', 'comments', 'shares', 'saves', 'profile_visits', 'enquiries',
                'engagement_rate', 'completion_rate', 'watch_hours', 'avg_watch_seconds',
                'leads', 'whatsapp_enquiries', 'calls', 'store_visits', 'orders',
                'sales_amount', 'sales_before_amount', 'roi',
                'benchmark_views', 'benchmark_reach', 'benchmark_engagements', 'benchmark_enquiries',
                'benchmark_sales_amount',
                'creative_hook', 'creative_concept', 'creative_storytelling', 'creative_cta',
                'creative_offer', 'creative_audience',
                'before_after',
            ]);
        });
    }
};
