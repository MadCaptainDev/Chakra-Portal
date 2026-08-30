<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flow definition: the visual graph an admin builds (nodes + edges,
 * serialised as JSON) plus what starts it.
 *
 * trigger_type is one of inbound_message (a catch-all default), keyword (a
 * substring match against trigger_config['keyword']), or label_applied
 * (fired when a conversation gets tagged -- wired up in a later task).
 * is_active gates which flows FlowEngine will even consider starting; a flow
 * being edited or retired stays in the table with is_active = false rather
 * than being deleted out from under any session already running against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger_type');
            $table->json('trigger_config')->nullable();
            $table->json('graph');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flows');
    }
};
