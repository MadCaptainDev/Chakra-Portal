<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Sync all Instagram": one row per account per run, worked through one at
 * a time (InstagramSyncQueue) so a slow sync never holds a page open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch');
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
            $table->index('batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_sync_jobs');
    }
};
