<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users permitted to see and complete a routine. In individual mode the
 * generator creates one open occurrence per permitted user per due date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['routine_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_user');
    }
};
