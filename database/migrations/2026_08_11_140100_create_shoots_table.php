<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A planned shoot: when, for whom, where, and who is going.
 *
 * The studio plans these in Notion today, and nothing about that reaches the
 * portal -- the synced content rows carry no shoot date at all. So this is the
 * first record in the system that looks forwards rather than backwards. The
 * timesheet knows a shoot happened; nothing knew one was going to.
 *
 * ends_at is nullable because "we're shooting Friday" is the normal amount of
 * certainty a week out. Everything that needs a window treats a missing end as
 * the rest of that day rather than as an open-ended booking, which would make
 * one undated shoot hold every camera forever.
 *
 * Kit state is NOT stored here. Whether the van is packed is derived from
 * shoot_kit, because a status column and a set of timestamps that can disagree
 * eventually will, and then nobody trusts either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoots', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 20)->default('planned');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The list defaults to what is coming up, soonest first.
            $table->index(['status', 'starts_at']);
            // Scanned on its own by the kit availability overlap check.
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoots');
    }
};
