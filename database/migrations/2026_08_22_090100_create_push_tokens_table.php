<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One browser that has agreed to receive push notifications.
 *
 * Shaped after mcp_tokens -- per-user, per-device, cascade on delete,
 * token_hash carrying the unique index -- with one deliberate difference:
 * the PLAINTEXT token is stored here, because it has to be handed to Google
 * on every send. A reader who knows mcp_tokens (which stores only the hash)
 * would otherwise read that as a mistake.
 *
 * That is safe: an FCM registration token names a device inside OUR Firebase
 * project and is useless to anybody without the service-account key. It is
 * not a credential to this portal, so it is not worth encrypting.
 *
 * text() not string(255): Google documents registration tokens as opaque
 * and up to 4 KB. Observed web tokens are ~163 characters, but a truncating
 * varchar would silently produce tokens that never deliver and give no clue
 * why -- so the length ceiling is not ours to assume. text has no index,
 * which is exactly why token_hash exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('device_label', 64)->nullable();
            $table->string('device_kind', 16)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->timestamps();

            // The profile screen lists one person's devices, newest first.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
