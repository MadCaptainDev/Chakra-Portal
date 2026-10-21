<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chakra Planner: the desktop app that plans content against the portal's
 * copy of the Notion planners. See routes/planner-api.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One signed-in copy of the app. The token itself is never stored.
        Schema::create('planner_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_name', 120);
            $table->string('platform', 40)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // One row, like notion_settings.
        Schema::create('planner_settings', function (Blueprint $table) {
            $table->id();
            $table->text('ably_key')->nullable();
            $table->text('notion_webhook_secret')->nullable();
            $table->timestamp('notion_webhook_secret_at')->nullable();
            $table->timestamp('notion_webhook_last_event_at')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Installers the app updates itself from.
        Schema::create('planner_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 40);
            $table->string('platform', 40);
            $table->string('file_path');
            $table->string('installer_path')->nullable();
            $table->text('signature')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['version', 'platform']);
        });

        // Who changed what on a planner item, from where.
        Schema::create('content_item_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field', 40);
            $table->string('from_value')->nullable();
            $table->string('to_value')->nullable();
            $table->string('origin', 20);
            $table->timestamps();
            $table->index(['content_item_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_item_changes');
        Schema::dropIfExists('planner_releases');
        Schema::dropIfExists('planner_settings');
        Schema::dropIfExists('planner_sessions');
    }
};
