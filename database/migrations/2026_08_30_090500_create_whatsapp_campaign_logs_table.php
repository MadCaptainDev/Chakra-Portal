<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per contact a campaign sent (or tried to send) to.
 *
 * The unique (campaign_id, contact_id) pair is what makes a campaign safe to
 * retry -- re-running it never double-messages someone who already has a
 * row. status climbs pending -> sent -> delivered -> read the same way the
 * webhook's own statuses do, so the two can share one badge vocabulary; wamid
 * is what ties a log row back to the delivery events WhatsappWebhookEvent
 * records once Meta reports them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaign_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('whatsapp_campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('whatsapp_contacts');

            // Captured at send time alongside the id, so the log still reads
            // correctly even if the contact's number is later changed.
            $table->string('phone');

            $table->string('status')->default('pending');
            $table->string('wamid')->nullable()->index();
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_campaign_logs');
    }
};
