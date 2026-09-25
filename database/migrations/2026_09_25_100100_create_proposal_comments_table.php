<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments on a proposal, from the client (through the public link, so no
 * user row -- a typed name and optional email) or from staff replying.
 *
 * section_key points at one entry of proposals.sections by its stable key;
 * null is a general "ideas / feedback" comment on the whole document. It is a
 * plain string, not a foreign key, because sections live in JSON -- a comment
 * on a section that is later deleted stays readable and is shown as general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->string('section_key', 64)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('proposal_comments')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 120);
            $table->string('author_email', 190)->nullable();

            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['proposal_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_comments');
    }
};
