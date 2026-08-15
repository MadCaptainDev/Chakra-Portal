<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp Cloud API connection, and everything Meta has sent us.
 *
 * Two tables because they answer two different questions. whatsapp_settings is
 * the one row that says how Meta reaches us; whatsapp_webhook_events is the log
 * of what actually arrived.
 *
 * The settings live in the database rather than in .env for one reason that
 * matters: the verify token has to be readable *and* rotatable from the admin
 * panel. An env var is neither -- reading it means SSH, and rotating it means a
 * deploy, which is exactly the friction that ends with the token pasted into a
 * WhatsApp group so somebody can "just try it".
 *
 * app_secret is encrypted for the same reason client credentials are: it is a
 * key we must be able to read back, because every incoming webhook is verified
 * against it. Anything holding APP_KEY can read it, and that is accepted --
 * the alternative is it living in a screenshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();

            /*
             * The shared secret Meta echoes back during the subscription
             * handshake. Not encrypted: it is a low-value nonce that proves
             * only that whoever configured the webhook had access to this
             * screen, it is displayed on that screen by design, and it can be
             * rotated in one click. Encrypting a value we deliberately show
             * would be theatre.
             */
            $table->string('verify_token', 64);

            // Verifies X-Hub-Signature-256 on every incoming POST. Without it
            // we cannot tell Meta from anyone else who learned the URL, so the
            // endpoint refuses to accept anything until it is set.
            $table->text('app_secret')->nullable();

            // Filled in from the Meta dashboard. Not used to receive -- these
            // are here so the numbers a person needs are in one place, and so
            // sending can be built on top without another settings screen.
            $table->string('phone_number_id')->nullable();
            $table->string('business_account_id')->nullable();
            $table->string('display_phone_number')->nullable();

            /*
             * Stamped by the handshake and by the first accepted POST. Together
             * they answer the only two questions anybody asks of this screen:
             * "did Meta accept my callback URL" and "is anything arriving".
             * A screen that cannot answer those is a screen you debug from the
             * server log instead, which is the thing this replaces.
             */
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_event_at')->nullable();

            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();

            // whatsapp_business_account. Stored rather than assumed, because one
            // callback URL can be subscribed to several products and the day a
            // Page event lands here we want the row, not a swallowed exception.
            $table->string('object', 50)->nullable();
            $table->string('field', 50)->nullable();

            // message | status | error | other -- what the row is, decided once
            // on the way in so the screen never re-parses the payload.
            $table->string('type', 20)->index();

            /*
             * Meta redelivers. A webhook that is not acknowledged within a few
             * seconds is sent again, and duplicates arrive in normal operation
             * too, so the same wamid will land here more than once.
             *
             * The unique key is a hash rather than the id itself because one
             * message id legitimately produces several rows -- sent, delivered
             * and read all carry the same wamid -- and because MySQL lets
             * duplicate NULLs through a unique index, which would quietly
             * defeat the whole guard for any payload without an id.
             */
            $table->string('dedupe_key', 64)->unique();

            $table->string('external_id')->nullable()->index();

            // Who it is from (a wa_id, i.e. a phone number in international
            // format) and the profile name WhatsApp supplied with it.
            $table->string('wa_id', 32)->nullable()->index();
            $table->string('contact_name')->nullable();

            // text | image | audio | document | button | interactive ...
            $table->string('message_type', 30)->nullable();
            // sent | delivered | read | failed, for status rows.
            $table->string('status', 20)->nullable();

            /*
             * A readable one-line preview: the message text, or the error Meta
             * reported. Truncated on the way in -- this column exists so the
             * admin list is legible without opening the payload, and the whole
             * truth is in `payload` regardless.
             */
            $table->text('summary')->nullable();

            // The entry exactly as Meta sent it. Everything above is derived
            // from this and can be re-derived; this is the record of fact.
            $table->json('payload');

            // When Meta says it happened, vs when it reached us. They differ
            // when Meta retries, and the gap is the first thing worth seeing
            // when someone reports a late message.
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');

            $table->timestamps();

            // The only way this table is read: newest first, optionally
            // narrowed to messages or to statuses.
            $table->index(['type', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('whatsapp_settings');
    }
};
