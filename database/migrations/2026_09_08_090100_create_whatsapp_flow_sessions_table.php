<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One in-progress (or finished) walk through a flow's graph for one WhatsApp
 * number.
 *
 * current_node_id is where FlowEngine resumes from -- null once the session
 * has ended. variables is the scratch space nodes read and write (ConditionNode
 * branches on it; the engine's own loop-protection bookkeeping lives under the
 * reserved `_visits` key so a node visit cap survives a resume). status walks
 * active -> completed|failed|expired, the same shape as every other status
 * column in this app.
 *
 * variables is nullable with no DB-level default rather than default('{}'):
 * a literal default on a JSON column is rejected by MySQL 8 in strict mode
 * (ER_BLOB_CANT_HAVE_DEFAULT). Every write path in FlowEngine already treats
 * a missing value as an empty array (`$session->variables ?? []`), and
 * WhatsappFlowSession::create() always passes variables explicitly, so a
 * null column value is never actually observed in practice -- this is
 * belt-and-suspenders for whatever isn't written through FlowEngine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('whatsapp_flows')->cascadeOnDelete();
            $table->string('wa_id')->index();
            $table->string('current_node_id')->nullable();
            $table->json('variables')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('iteration_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_advanced_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flow_sessions');
    }
};
