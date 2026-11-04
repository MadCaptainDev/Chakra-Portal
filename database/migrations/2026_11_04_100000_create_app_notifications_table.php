<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The notification inbox: every push the portal sends to a teammate, kept
 * whether or not a device was there to receive it. The mobile app reads it
 * (GET /api/mobile/notifications) -- on an iPhone built with a free Apple
 * account there is no remote push at all, so this list IS the notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->string('title', 255);
            $table->text('body');
            $table->string('url', 500)->default('/');
            $table->string('tag', 120)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
