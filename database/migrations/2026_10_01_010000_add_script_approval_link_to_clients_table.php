<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A no-login link for the client's script approval queue -- same convention
 * as client_briefs.public_token and proposals.public_token: a long random
 * token that IS the credential, one live token per client, reissuing
 * replaces it and revoking nulls it.
 *
 * Most clients never sign into the portal, so the queue at client/scripts is
 * reachable behind a login only; this is the same screen behind a link that
 * can be sent over WhatsApp instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('script_approval_token', 48)->nullable()->unique()->after('portal_sections_disabled');
            $table->timestamp('script_approval_token_issued_at')->nullable()->after('script_approval_token');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['script_approval_token', 'script_approval_token_issued_at']);
        });
    }
};
