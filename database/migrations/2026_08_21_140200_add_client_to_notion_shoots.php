<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which portal client a Notion shoot belongs to.
 *
 * Notion's "Client" is a free-text select -- "SVA", "Suryas", "Vinu",
 * "Thillai Pet Clinic" -- and only four of the eleven spellings in use
 * resolve to a portal client by exact match. The rest need a person, so
 * this is a real column that a mapping screen writes, not something
 * guessed on every read.
 *
 * The sync never overwrites a value already set here: an id assigned by
 * hand outranks anything a matcher would infer, and having a re-sync
 * silently undo that mapping is how people stop trusting the screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notion_shoots', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('client')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notion_shoots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
