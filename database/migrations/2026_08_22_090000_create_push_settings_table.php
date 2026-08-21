<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The studio's Firebase credentials. One row, like notion_settings /
 * whatsapp_settings.
 *
 * Only ONE of these three columns is encrypted, and the asymmetry is
 * deliberate rather than an oversight:
 *
 *   service_account_json carries an RSA private key that can send a push
 *   as the studio to every registered device. It is the only credential
 *   here, and it gets the same treatment as WhatsappSetting::$access_token.
 *
 *   web_config and vapid_public_key are handed to every browser that loads
 *   the opt-in screen -- that is what they are FOR. Encrypting them at rest
 *   would protect nothing while implying they are secrets, which invites
 *   somebody to later "fix" the exposure that is the entire point of them.
 *
 * text() rather than string() for the two long ones: Laravel's AES
 * ciphertext runs roughly 3x the plaintext and a service-account JSON is
 * already ~2.3 KB. Same reasoning as the notion_settings migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_settings', function (Blueprint $table) {
            $table->id();
            $table->text('service_account_json')->nullable();
            $table->text('web_config')->nullable();
            $table->string('vapid_public_key')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_settings');
    }
};
