<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved reply someone on the team can drop into a conversation without
 * retyping it -- the WhatsApp equivalent of an email canned response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title');

            // text today; the column exists so a future media/template quick
            // reply does not need a schema change to add its own type.
            $table->string('type')->default('text');
            $table->text('content');

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_quick_replies');
    }
};
