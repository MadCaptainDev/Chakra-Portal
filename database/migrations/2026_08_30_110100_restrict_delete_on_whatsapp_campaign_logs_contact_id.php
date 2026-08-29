<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a gap left by the original whatsapp_campaign_logs migration:
 * contact_id was `constrained()` with no delete rule, which on most drivers
 * defaults to NO ACTION -- functionally similar to restrict, but silent and
 * driver-dependent rather than a stated intent. Made explicit here: a
 * contact referenced by campaign send history must not be deletable out from
 * under it. Cascading it away would quietly erase what was actually sent;
 * blocking the delete is the safer failure mode -- see
 * WhatsappContactController::destroy(), which now catches the resulting FK
 * violation and turns it into a clear flash message instead of a raw 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaign_logs', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
        });

        Schema::table('whatsapp_campaign_logs', function (Blueprint $table) {
            $table->foreign('contact_id')->references('id')->on('whatsapp_contacts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaign_logs', function (Blueprint $table) {
            $table->dropForeign(['contact_id']);
        });

        Schema::table('whatsapp_campaign_logs', function (Blueprint $table) {
            $table->foreign('contact_id')->references('id')->on('whatsapp_contacts');
        });
    }
};
