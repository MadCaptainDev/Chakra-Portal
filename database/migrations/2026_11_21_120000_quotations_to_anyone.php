<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quotation can go to anyone, not only a client already in the portal:
 * a name, a WhatsApp number and an address on the quotation itself.
 * client_id becomes optional; converting to an invoice makes the client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->change();
            $table->string('recipient_name')->nullable()->after('client_id');
            $table->string('recipient_phone', 30)->nullable()->after('recipient_name');
            $table->string('recipient_address')->nullable()->after('recipient_phone');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', fn (Blueprint $t) => $t->dropColumn(['recipient_name', 'recipient_phone', 'recipient_address']));
    }
};
