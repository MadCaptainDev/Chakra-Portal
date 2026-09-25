<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A proposal: a long, designed document the studio sends a prospect before
 * there is anything to quote line by line -- scope, phases, architecture,
 * commercials, terms.
 *
 * The body is one ordered JSON list of sections rather than a sections table.
 * A proposal is written, reordered and duplicated as a whole, never queried by
 * its parts, and the shape of each block differs by type (a table, a flow of
 * steps, a swimlane) -- a column per possibility would be mostly nulls. See
 * App\Support\ProposalBlocks for the shapes.
 *
 * The share link copies client_briefs' public link: a long random token that
 * IS the credential, one live token per proposal, reissuing replaces it and
 * revoking nulls it -- so "that link went to the wrong person" is fixable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('status', 20)->default('draft');
            $table->json('sections')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
