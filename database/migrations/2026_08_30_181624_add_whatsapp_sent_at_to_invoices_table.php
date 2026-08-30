<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Set when the invoice was last handed to the client over
            // WhatsApp -- lets the show page say "Sent on ..." instead of a
            // bare button that gives no sign whether today's tap already
            // went through.
            $table->timestamp('whatsapp_sent_at')->nullable()->after('approved_at');

            // The client-facing link's only credential -- same convention as
            // ClientBrief::public_token (see PublicInvoiceController):
            // Meta's own template-button rules force the link into
            // "https://.../i/{{1}}" shape (a static base plus one path
            // segment), which a signed-route query string cannot satisfy,
            // so this is a stored token rather than a signature.
            $table->string('public_token', 48)->nullable()->unique()->after('whatsapp_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_sent_at', 'public_token']);
        });
    }
};
