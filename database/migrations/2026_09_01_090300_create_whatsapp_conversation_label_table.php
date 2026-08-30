<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pivot between conversations and labels -- a conversation can carry more
 * than one label, and a label is just the set of conversations attached here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversation_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('whatsapp_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_label');
    }
};
