<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The studio's own Instagram app credentials. One row, like whatsapp_settings.
 *
 * In the database rather than .env for the reason the WhatsApp settings give:
 * rotating a secret should be a person on a settings screen, not SSH and a
 * deploy. The alternative ends with the secret pasted into a chat so somebody
 * with server access can apply it, which is the exposure the encryption is
 * meant to prevent.
 *
 * app_id is plain text on purpose -- it travels in the authorize URL in every
 * client's browser, so treating it as secret would be theatre. app_secret is
 * encrypted with APP_KEY, the same as ClientCredential::$secret.
 *
 * The redirect URI is NOT stored. It is derived from config('app.url'), so it
 * cannot drift from what was registered in the Meta dashboard depending on
 * which hostname an admin happened to sign in through -- Meta matches it
 * exactly and a mismatch is the single most common reason this flow fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_settings', function (Blueprint $table) {
            $table->id();

            // The Instagram App ID and Secret from the Instagram product's
            // "Instagram Login for Business" panel -- NOT the Meta app's own
            // id and secret, which are different values on the same dashboard
            // and produce a confusing "invalid platform app" if used here.
            $table->string('app_id')->nullable();
            $table->text('app_secret')->nullable();

            /*
             * Stamped by the first connection that completes. Answers "did
             * anybody ever get this working", which is otherwise a question
             * only the logs can settle.
             */
            $table->timestamp('verified_at')->nullable();

            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_settings');
    }
};
