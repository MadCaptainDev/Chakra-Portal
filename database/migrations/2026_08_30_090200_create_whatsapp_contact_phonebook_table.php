<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pivot between contacts and phonebooks -- a contact can sit in more
 * than one list, and a phonebook is just the set of contacts attached here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contact_phonebook', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained('whatsapp_contacts')->cascadeOnDelete();
            $table->foreignId('phonebook_id')->constrained('whatsapp_phonebooks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'phonebook_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contact_phonebook');
    }
};
