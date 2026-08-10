<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One master table for every controlled list in the app.
     *
     * The portfolio grew a handful of free-text boxes -- platform, format,
     * objective -- and predictably filled up with "Instagram Reels" and
     * "Instagram reels" as separate values. Rather than adding a table per
     * list (and a controller, and a screen, and a migration each time the
     * studio wants a new one), every list lives here under a `type`.
     *
     * Adding a list later is a constant on the model, not a migration. The
     * table is deliberately named for what it is rather than for the portfolio,
     * because clients already need one of these too (industry) and other
     * modules will.
     *
     * Terms are retired with `is_active`, not deleted: an inactive term
     * disappears from the pickers while every piece already using it keeps
     * reading correctly. Deleting still works, and nulls the reference.
     */
    public function up(): void
    {
        Schema::create('taxonomy_terms', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Two platforms may not share a slug; a platform and a tag may.
            $table->unique(['type', 'slug']);
            $table->index(['type', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_terms');
    }
};
