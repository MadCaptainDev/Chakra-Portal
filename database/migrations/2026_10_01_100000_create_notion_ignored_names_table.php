<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notion names somebody has looked at and decided are not a client --
 * "Others", a one-off, a test entry -- so the connections screen stops
 * asking about them.
 *
 * kind is 'venture' (Content tables' Venture) or 'shoot_client' (the Shoots
 * table's Client): the two are separate free-text lists in Notion. Ignoring
 * is only a statement about the asking; nothing is deleted, and a restored
 * name reappears exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notion_ignored_names', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('name');
            $table->timestamps();

            $table->unique(['kind', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notion_ignored_names');
    }
};
