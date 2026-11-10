<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My ventures" on the admin dashboard: which Notion ventures a person
 * watches, how they want to hear about them, and what the planner last
 * looked like so a change can be told once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venture_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The Notion "Venture" option, exactly as the planner stores it.
            $table->string('venture');
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Set when "running low" was told; cleared once stock recovers.
            $table->timestamp('low_alerted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'venture']);
        });

        Schema::create('venture_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('daily_summary')->default(true);
            $table->string('summary_at', 5)->default('09:00');
            $table->date('summary_sent_on')->nullable();
            // Videos shot and not yet out; fewer than this is "running low".
            $table->unsignedSmallInteger('low_stock')->default(4);
            $table->boolean('notify_low')->default(true);
            $table->boolean('notify_ready')->default(true);
            $table->boolean('notify_published')->default(true);
            $table->boolean('notify_shot')->default(false);
            // Also as a WhatsApp message, when Meta allows one (24-hour window).
            $table->boolean('whatsapp')->default(true);
            $table->timestamps();
        });

        // What each watched item's status was the last time alerts ran.
        Schema::create('venture_item_states', function (Blueprint $table) {
            $table->unsignedBigInteger('content_item_id')->primary();
            $table->string('status', 100)->nullable();
            $table->timestamp('seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venture_item_states');
        Schema::dropIfExists('venture_alert_settings');
        Schema::dropIfExists('venture_watches');
    }
};
