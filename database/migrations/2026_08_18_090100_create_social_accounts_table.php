<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A social account the studio has been authorised to read on a client's behalf.
 *
 * Deliberately NOT an instagram_accounts table and deliberately not columns on
 * `clients`. The studio already runs YouTube channels and Facebook pages for
 * the same clients, and the day one of those is connected the choice is either
 * a second table shaped exactly like this one, or this one with a different
 * value in `platform`.
 *
 * What this replaces matters: client_credentials holds these clients' actual
 * Instagram passwords, because reading their numbers meant logging in as them.
 * A token here is scoped, revocable by the client, and cannot post or change a
 * password -- so every account connected this way is one the studio no longer
 * needs the password for.
 *
 * The parts worth knowing before changing anything:
 *
 * - access_token is encrypted with APP_KEY, like every other recoverable
 *   secret in this app. It is never rendered, never logged, and never sent to
 *   a browser.
 * - unique (platform, platform_user_id) stops one Instagram account being
 *   attached to two clients. Without it a mis-click during onboarding splits
 *   one account's analytics across two clients and neither total is right.
 * - unique (client_id, platform) is the reconnect key: connecting again is an
 *   updateOrCreate, so the row id survives and any history hanging off it stays
 *   attached. Disconnect flips `status` and clears the token rather than
 *   deleting, for the same reason.
 * - last_error is for a human. Meta's messages are unusually good ("The user
 *   is not an Instagram Business account") and are worth showing verbatim --
 *   but the token that produced the error is never written beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // instagram today; youtube / facebook later.
            $table->string('platform', 20);

            // The platform's own id for the account. Kept because usernames
            // change and this does not -- a client who renames their handle
            // must not look like a different account on the next sync.
            $table->string('platform_user_id')->nullable();

            $table->string('username')->nullable();
            // BUSINESS | MEDIA_CREATOR. Instagram refuses to connect personal
            // accounts at all, so this is recorded to explain a refusal rather
            // than to gate on.
            $table->string('account_type', 30)->nullable();
            $table->string('profile_picture_url', 500)->nullable();
            $table->unsignedInteger('followers_count')->nullable();

            $table->text('access_token')->nullable();
            // Long-lived Instagram tokens last about 60 days and are refreshed
            // by using them. Stored so a sync can refresh before expiry rather
            // than discovering it from a failed call.
            $table->timestamp('token_expires_at')->nullable();

            // What was actually granted, which is not always what was asked:
            // a client can untick a permission on the consent screen.
            $table->json('scopes')->nullable();

            // connected | revoked | error
            $table->string('status', 20)->default('connected');

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->foreignId('connected_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['platform', 'platform_user_id']);
            $table->unique(['client_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
