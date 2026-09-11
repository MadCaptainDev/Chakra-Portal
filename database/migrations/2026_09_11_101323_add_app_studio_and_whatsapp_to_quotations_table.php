<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            // Which side of Chakra this quotation is for -- unlike Invoice,
            // no product picker: a quotation is pricing hypothetical work,
            // not billing a specific SaasProduct's AMC, so there is nothing
            // to link to yet. Just a label until (if ever) it becomes an
            // invoice, where the product actually gets chosen.
            $table->boolean('is_app_studio')->default(false)->after('client_id');

            // Same convention as Invoice::whatsapp_sent_at/public_token: the
            // no-login link a "Send via WhatsApp" template button points at.
            $table->timestamp('whatsapp_sent_at')->nullable()->after('rejected_at');
            $table->string('public_token', 48)->nullable()->unique()->after('whatsapp_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['is_app_studio', 'whatsapp_sent_at', 'public_token']);
        });
    }
};
