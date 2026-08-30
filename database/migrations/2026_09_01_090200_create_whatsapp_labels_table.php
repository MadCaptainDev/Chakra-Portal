<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A tag a conversation can be filed under -- "VIP", "Needs reply", and so on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_labels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_labels');
    }
};
