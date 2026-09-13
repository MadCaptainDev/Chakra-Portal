<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the assistant and the admin have said to each other, and what it
 * looked up to answer.
 *
 * Not the same thing as whatsapp_webhook_events, which is the log of record
 * for what Meta delivered. This is the *conversation*: the turns that get
 * replayed to the model on the next message, which is a different shape and
 * a different lifetime -- and which must never include a tool's output.
 *
 * That last point is the one worth being explicit about. Only the words are
 * replayed; the figures are not. A tool result stored here and replayed
 * tomorrow would be yesterday's money quoted as today's, and the model has
 * no way to know. So `tool_calls` records what was called for the audit
 * trail and for anyone reading the thread back later, and the next turn
 * looks every figure up again from the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_agent_messages', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 16);
            $table->text('body');
            /*
             * Audit only, never replayed: a list of {name, input} for every
             * tool the model reached for on this turn. Nullable because a
             * turn that needed nothing looked nothing up, and an empty array
             * would read as "we recorded none" rather than "there were none".
             */
            $table->json('tool_calls')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_agent_messages');
    }
};
