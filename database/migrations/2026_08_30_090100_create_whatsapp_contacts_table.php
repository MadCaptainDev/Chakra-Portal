<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person a campaign or a quick reply can reach.
 *
 * phone is unique and stored already normalised (see WhatsappContact's
 * mutator), so import, campaign send and webhook lookups can all match on it
 * directly rather than each re-deriving their own idea of "the same number".
 *
 * var1..var5 are free-form merge fields -- a name, a package, a shoot date --
 * whatever a campaign's template needs positionally. Five is the same shape
 * WhatsApp template body parameters already take, not an arbitrary cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('name')->nullable();
            $table->string('var1')->nullable();
            $table->string('var2')->nullable();
            $table->string('var3')->nullable();
            $table->string('var4')->nullable();
            $table->string('var5')->nullable();

            // Where this contact came from -- a CSV import, an enquiry, typed
            // in by hand -- so a bad batch can be traced back to its source.
            $table->string('source')->nullable();

            // Set the moment a contact asks to stop hearing from us. Checked
            // before every send rather than deleting the row, so "why did we
            // message them" stays answerable.
            $table->timestamp('opted_out_at')->nullable();

            // The last time this number sent us anything, which is what
            // decides whether a free-text reply is still legal to send.
            $table->timestamp('last_interacted_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};
