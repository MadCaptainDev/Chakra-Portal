<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Video ready that has been scheduled on Instagram or YouTube for its
 * publishing time (My Editing): no reminder nags it, and it counts as
 * posted once the time comes (editing:publish-reminders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('publish_at');
            $table->foreignId('scheduled_by_id')->nullable()->after('scheduled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_by_id');
            $table->dropColumn('scheduled_at');
        });
    }
};
