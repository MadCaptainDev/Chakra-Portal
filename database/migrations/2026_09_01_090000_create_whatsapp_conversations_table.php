<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One conversation thread per WhatsApp number -- the inbox groups every
 * message and status WhatsappWebhookEvent has logged for a wa_id under this
 * one row, which is what unread_count and last_message_summary track.
 *
 * contact_id is nullable: a wa_id can message before it exists as an imported
 * WhatsappContact (or ever), and the inbox has to keep working either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id')->unique();
            $table->foreignId('contact_id')->nullable()->constrained('whatsapp_contacts')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_summary')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
