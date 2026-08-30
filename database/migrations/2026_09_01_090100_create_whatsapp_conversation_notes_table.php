<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An internal note left on a conversation -- never sent to the contact, just
 * context one teammate leaves for whoever picks the thread up next.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_notes');
    }
};
