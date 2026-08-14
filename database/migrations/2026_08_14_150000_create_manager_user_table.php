<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who signs somebody's work off -- now more than one person.
 *
 * users.manager_id could only hold one name, which does not match how the
 * studio runs: an editor answers to the producer on the job and to the studio
 * lead on the week, and whichever of them is on set that day is the one who can
 * actually look at the work. One manager meant work waited on a person who was
 * unreachable, and the only way round it was to make somebody an admin.
 *
 * user_id is the person being managed; manager_id is the manager. Both cascade:
 * the row is a statement about two accounts and means nothing once either is
 * gone. The pair is unique, so naming the same manager twice is a no-op rather
 * than a duplicate that has to be filtered out at read time.
 *
 * users.manager_id is left in place and stops being written. Dropping it would
 * take the record of who reported to whom before today with it, and this app's
 * habit is to retire a column rather than remove it. Everything is backfilled
 * here, so no employee loses a manager in the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'manager_id']);

            // "Whose work lands in my queue?" is the question asked on every
            // page load of the team screens.
            $table->index('manager_id');
        });

        $existing = DB::table('users')
            ->whereNotNull('manager_id')
            ->get(['id', 'manager_id']);

        foreach ($existing->chunk(200) as $chunk) {
            DB::table('manager_user')->insert(
                $chunk->map(fn ($row) => [
                    'user_id' => $row->id,
                    'manager_id' => $row->manager_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_user');
    }
};
