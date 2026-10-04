<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per MCP tool call, for the Developer page's Activity tab: who
 * called what, with which arguments, whether it worked and how long it took.
 * The result itself is not kept -- it can be a page of client money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mcp_token_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool', 80)->index();
            $table->text('arguments')->nullable();
            $table->boolean('ok');
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_call_logs');
    }
};
