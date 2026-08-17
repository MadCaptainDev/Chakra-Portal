<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Instagram webhook: a verify token, and somewhere to put what arrives.
 *
 * Separate from the OAuth redirect URI and easily confused with it, which is
 * the reason for this note. They are two different URLs doing two different
 * jobs:
 *
 *   /oauth/instagram/callback   where a CLIENT lands after authorising.
 *                               Behind `auth` -- a person is signed in.
 *   /webhooks/instagram         where META posts events. Public by necessity:
 *                               Meta verifies it with an anonymous GET and has
 *                               no cookie to send.
 *
 * Putting the OAuth URL in the webhook field is the failure this schema exists
 * to make hard to reach -- the verification GET would be answered with a
 * redirect to the login page, and Meta reports only that it could not be
 * validated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_settings', function (Blueprint $table) {
            /*
             * The shared nonce Meta echoes during the subscription handshake.
             * Not encrypted: it is a low-value value displayed on the settings
             * screen by design, and it can be rotated in one click. Encrypting
             * something deliberately shown would be theatre -- the same
             * reasoning as the WhatsApp verify token.
             */
            $table->string('verify_token', 64)->nullable()->after('app_secret');
            $table->timestamp('webhook_verified_at')->nullable()->after('verified_at');
        });

        Schema::create('social_webhook_events', function (Blueprint $table) {
            $table->id();

            // instagram today, and the same table when the next platform
            // starts posting -- these all have the same shape: a subject, a
            // field that changed, and a payload worth keeping.
            $table->string('platform', 20);

            // The account the event is about, when we can work it out. Null
            // when Meta writes about an account nobody has connected, which
            // must still be recorded rather than dropped.
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();

            $table->string('object', 50)->nullable();
            $table->string('field', 50)->nullable();
            // The sender/subject id straight from the payload.
            $table->string('external_id')->nullable()->index();

            /*
             * Meta redelivers anything it does not get a prompt 200 for, so
             * the same event arrives more than once in normal operation. A
             * hash rather than the id itself because MySQL lets duplicate
             * NULLs through a unique index, which would defeat the guard for
             * any payload without one.
             */
            $table->string('dedupe_key', 64)->unique();

            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['platform', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_webhook_events');

        Schema::table('instagram_settings', function (Blueprint $table) {
            $table->dropColumn(['verify_token', 'webhook_verified_at']);
        });
    }
};
