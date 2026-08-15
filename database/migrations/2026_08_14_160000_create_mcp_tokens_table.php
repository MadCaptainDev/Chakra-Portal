<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens that let an AI client act as one person over the MCP endpoint.
 *
 * Only the hash is stored, for the same reason a password is only ever stored
 * hashed: this table is the highest-value row in the database once it exists --
 * a plaintext token in it is a working login to somebody's account, readable by
 * anyone who gets a database dump or an errant query into a log. The token is
 * shown to its owner exactly once, at the moment it is made, and is
 * unrecoverable after that. Lost means make another.
 *
 * sha256 rather than bcrypt, deliberately and unusually. A password is short,
 * guessable and typed by a human, so it needs a slow hash to survive being
 * brute-forced. This token is 40 random characters from a 62-character
 * alphabet, which is far past what any offline attack reaches -- and it is
 * verified on *every single request* an AI client makes, where bcrypt's
 * deliberate slowness would be the endpoint's performance ceiling. Fast hash,
 * high entropy; the entropy is doing the work.
 *
 * A token belongs to a person and inherits exactly their permissions -- there
 * is no scope column and no way to grant a token more than its owner has. That
 * is the point: revoking somebody's access revokes their tokens with it,
 * because cascadeOnDelete takes the rows when the account goes.
 *
 * last_used_at is the only telemetry, and it is the useful one: it answers "is
 * this old token still live?" when somebody is deciding what to revoke.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What the owner called it -- "my laptop", "the studio Mac".
            $table->string('name');
            $table->string('token_hash', 64)->unique();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Every request authenticates by hash, and the profile screen lists
            // one person's newest first.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
