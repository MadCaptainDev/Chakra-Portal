<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A team's end-of-day checklist, as sent on WhatsApp ("EDITING TEAM | EOD
 * CHECK") -- one row per team per day, the latest message winning. Items are
 * the checklist lines with a tick or a cross; status is what the message
 * said ("Pending", "Done").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_checks', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('team', 80);
            $table->json('items');
            $table->string('status', 40)->nullable();
            $table->foreignId('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->timestamps();
            $table->unique(['date', 'team']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_checks');
    }
};
