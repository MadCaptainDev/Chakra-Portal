<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A script: the writing that goes into one piece of content.
 *
 * Hangs off client_id, not a venture string. "Venture" in this app is a label
 * derived from the client (see App\Support\TimesheetVenture) and exists only
 * because two legacy imports speak in names rather than ids. The most recent
 * modelling decision here -- linking portfolio items to clients -- moved the
 * other way deliberately, and this follows it.
 *
 * Everyone named on a script is nullable and nullOnDelete: a writer can leave
 * the studio without taking their scripts with them, and an unassigned script
 * is a normal state rather than an error.
 *
 * last_edited_by_id / last_edited_at are denormalised from the sections on
 * purpose. The list screen shows "who touched this last" for every row, and
 * deriving that per row would mean a query per script.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status', 30)->default('draft');
            $table->string('priority', 20)->default('normal');

            $table->foreignId('writer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('campaign')->nullable();

            // The shared master lists, so a script's platform is the same
            // vocabulary the portfolio already uses.
            $table->foreignId('platform_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->foreignId('script_type_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained('taxonomy_terms')->nullOnDelete();

            $table->unsignedInteger('target_seconds')->nullable();
            $table->date('due_on')->nullable();

            $table->foreignId('last_edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_edited_at')->nullable();

            $table->timestamps();

            // The list defaults to open work, soonest deadline first.
            $table->index(['status', 'due_on']);
            // "Assigned to me" on a writer's own dashboard.
            $table->index(['writer_id', 'status']);
            // Filtering the list by client.
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scripts');
    }
};
