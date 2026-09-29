<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keys for the home-screen widget (the Scriptable script on an iPhone).
 *
 * Its own table rather than a row in mcp_tokens on purpose. An MCP token can
 * write -- log hours, close to-dos, edit shoots. A widget key sits in plain
 * text inside a script on somebody's phone, so it must only ever be able to
 * read one small summary, and a separate table is what makes it impossible
 * for the MCP endpoint to accept one by mistake.
 *
 * Hashed exactly like mcp_tokens (see that migration for why sha256).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What the owner called it -- "my iPhone".
            $table->string('name');
            $table->string('token_hash', 64)->unique();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_tokens');
    }
};
