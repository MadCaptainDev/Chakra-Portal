<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening lines Gemini suggested for a script. One row per ask, so asking
 * again for the same script and note reuses the answer instead of paying
 * for it twice. See App\Services\Ai\HookWriter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language', 20)->default('script');
            $table->text('note')->nullable();
            $table->json('hooks');
            $table->string('input_hash', 64);
            $table->string('model', 80)->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['script_id', 'input_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_hooks');
    }
};
