<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Claude-generated Reel concept, adapted from a competitor's analyzed
 * reel for a particular brand.
 *
 * Many rows per analysis, on purpose -- an admin can ask for a fresh batch
 * of concepts with different brand instructions without losing the earlier
 * ones, since two attempts at the same reel for two different clients (or
 * two different moods for the same client) are both worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competitor_reel_analysis_id')->constrained()->cascadeOnDelete();
            // Optional: which client's brand this batch was generated for.
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // A snapshot of what was actually sent to Claude, not a pointer to
            // it -- the client's brief can change later, and this row should
            // keep describing the instructions that produced THIS concept.
            $table->text('brand_prompt');
            $table->longText('concept_text');

            $table->foreignId('generated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_concepts');
    }
};
