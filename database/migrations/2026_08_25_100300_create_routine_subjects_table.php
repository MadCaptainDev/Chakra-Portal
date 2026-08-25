<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which concrete subjects a routine fans out across. Polymorphic so a
 * routine can target Instagram accounts today and something else later
 * without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routine_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->timestamps();

            $table->unique(['routine_id', 'subject_type', 'subject_id'], 'routine_subjects_unique');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_subjects');
    }
};
