<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named list of contacts a campaign can be sent to.
 *
 * Deliberately just a name and a description -- the contacts themselves live
 * in whatsapp_contacts and are attached through the pivot, so the same
 * contact can sit in more than one phonebook without being copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_phonebooks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phonebooks');
    }
};
