<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked for the work, and whose it is.
 *
 * To-dos started out as something each person wrote for themselves. Real work
 * does not arrive that way -- a producer hands an edit to an editor, a client
 * call turns into three jobs for three people -- so anybody may now write a
 * to-do for anybody. user_id stays the person who has to do it, which is what
 * the board is grouped by; assigned_by_id is the person who asked.
 *
 * Nullable only so deleting an account does not take the record of what they
 * handed out with it, the way todo_updates.user_id and
 * timesheet_days.reviewed_by_id are nullable. Existing rows are backfilled to
 * their owner rather than left null, so "who asked for this" always has an
 * answer and self-assigned is a comparison rather than a null check.
 *
 * venture is the same concept as the timesheet's, deliberately the same column
 * name and the same value set: a to-do and the entry that eventually logs it
 * are the same piece of work seen from either end, and two different vocabularies
 * for the client would make that impossible to line up. Nullable in the schema
 * and required by the form, exactly as timesheet_entries.venture is -- the
 * "All / Multiple Clients" option is what covers work that is not one client's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->foreignId('assigned_by_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();

            $table->string('venture')->nullable()->after('title');
        });

        // Everything written before this was written by its owner.
        DB::table('todos')->update(['assigned_by_id' => DB::raw('user_id')]);

        Schema::table('todos', function (Blueprint $table) {
            // "What have I given people, and is any of it stuck?"
            $table->index(['assigned_by_id', 'starts_on']);
            $table->index('venture');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropIndex(['assigned_by_id', 'starts_on']);
            $table->dropIndex(['venture']);
            $table->dropConstrainedForeignId('assigned_by_id');
            $table->dropColumn('venture');
        });
    }
};
