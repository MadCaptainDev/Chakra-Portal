<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two Notion "Shoots" properties the portal did not read until now:
 * "Client Conformation" (Yes / No) and "Content" (Not Yet / Ready / No
 * Need). The evening check (shoots:tomorrow-check) uses both to say which
 * of tomorrow's shoots are not ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notion_shoots', function (Blueprint $table) {
            $table->string('client_confirmed', 20)->nullable();
            $table->string('content_ready', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notion_shoots', fn (Blueprint $table) => $table->dropColumn(['client_confirmed', 'content_ready']));
    }
};
