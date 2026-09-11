<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "Send via WhatsApp" attempt on an Invoice or a Quotation,
 * success or failure -- the thing a single whatsapp_sent_at timestamp
 * couldn't answer: who was it sent to, when, with what template, and did
 * it actually go through. Polymorphic so Invoice and Quotation (and
 * anything else that grows a WhatsApp send button later) share one table
 * and one log UI rather than each keeping its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_send_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('loggable');
            $table->string('phone');
            $table->string('template');
            $table->string('status');
            $table->string('wamid')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_send_logs');
    }
};
