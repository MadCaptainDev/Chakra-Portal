<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each person may do over MCP, set by an admin on the Developer page.
 * No row means the defaults: on, unlimited, every tool their permissions
 * allow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_user_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('daily_limit')->nullable();
            $table->boolean('read_only')->default(false);
            $table->boolean('can_message_clients')->default(true);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_user_limits');
    }
};
