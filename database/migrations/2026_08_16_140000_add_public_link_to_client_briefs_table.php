<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link the studio can send a client who has no portal login.
 *
 * Most clients never sign in. Asking somebody to accept an account, choose a
 * password and find the right screen before they can answer eight questions
 * about their own business is how a brief comes back as a voice note instead.
 * This is the same form behind a long random URL.
 *
 * The token is the credential, so it is treated like one:
 *
 * - Long and random, not derived from the client id. A guessable link would
 *   hand one client the brief of another, and these answers are commercially
 *   sensitive -- pricing position, what makes customers hesitate.
 * - One live token per brief. Reissuing replaces it, which is what makes
 *   "that link went to the wrong person" a fixable mistake.
 * - Unique across the table, so a lookup is by token alone and there is no
 *   client id in the path to tamper with -- the same argument the signed-in
 *   brief routes make by having no {client} segment.
 *
 * The link dies on submit rather than on a timer. A deadline nobody set is a
 * link that expires the evening the client finally sits down with it; "once
 * they have answered, it is closed" is a rule that explains itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_briefs', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('status');
            $table->timestamp('token_issued_at')->nullable()->after('public_token');
            $table->foreignId('token_issued_by_id')->nullable()->after('token_issued_at')
                ->constrained('users')->nullOnDelete();

            /*
             * Stamped when a brief arrives through the public link rather than
             * from a signed-in client. Worth keeping separate from
             * submitted_by_id, which points at a user row that does not exist
             * for a public submission -- and worth knowing when somebody asks
             * "who actually filled this in".
             */
            $table->timestamp('public_submitted_at')->nullable()->after('token_issued_by_id');
            $table->string('public_submitted_name')->nullable()->after('public_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_briefs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('token_issued_by_id');
            $table->dropColumn(['public_token', 'token_issued_at', 'public_submitted_at', 'public_submitted_name']);
        });
    }
};
