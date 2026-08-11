<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module-level permissions, one row per granted ability.
 *
 * Rows rather than a JSON column on users, because the interesting questions
 * are the reverse ones -- "who can approve a script?", "does anyone still have
 * finance access?" -- and a JSON blob answers those only by scanning every
 * user and decoding. Rows also get a foreign key, so deleting a login cannot
 * leave permissions behind.
 *
 * Deliberately NOT a roles table. With five staff, named roles would be a
 * layer of indirection over a list that fits on one screen. If presets are
 * wanted later they can write these rows; nothing here has to change.
 *
 * The module and ability strings are validated against App\Support\Permission
 * rather than the database, so adding a module stays a constant rather than a
 * migration -- the same bargain taxonomy_terms makes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 40);
            $table->string('ability', 20);
            $table->timestamps();

            // Granting the same ability twice is meaningless, and the unique
            // key is what lets a sync be written as delete-then-insert without
            // worrying about duplicates racing in.
            $table->unique(['user_id', 'module', 'ability']);

            // Every authorization check is "what may this user do here", and
            // the sidebar asks it once per module on every page render.
            $table->index(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
