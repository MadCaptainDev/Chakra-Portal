<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_whatsapp_portal_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('state')->default('menu');
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_whatsapp_portal_sessions');
    }
};
