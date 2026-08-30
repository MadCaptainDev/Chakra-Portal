<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where FlowEngine records why a session ended up `failed` -- a node
 * handler's exception message, or the reason one of the loop-protection
 * caps tripped. Without this, a stuck flow has nothing to show for why it
 * stopped short of reading application logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });
    }
};
