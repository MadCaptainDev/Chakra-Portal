<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The studio's Notion integration token. One row, like instagram_settings /
 * whatsapp_settings.
 *
 * Rotating a secret should be a person on a settings screen, not SSH and a
 * deploy -- see InstagramSetting for the same reasoning. text(), not
 * string(), for api_key: the AES ciphertext Laravel's 'encrypted' cast
 * produces is roughly 3x the plaintext length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notion_settings', function (Blueprint $table) {
            $table->id();
            $table->text('api_key')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notion_settings');
    }
};
