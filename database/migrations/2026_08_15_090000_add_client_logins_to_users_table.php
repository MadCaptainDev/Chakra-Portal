<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A login that belongs to a client rather than to the studio.
 *
 * Third value for users.role, and the client it speaks for. One table and one
 * guard, the same decision the employee role already represents: a second auth
 * system would double every question about who can see what, and the answer
 * would have to be kept in agreement forever.
 *
 * nullOnDelete rather than cascade. Deleting a client must not silently delete
 * an account -- it leaves a login with no client, which the client screen shows
 * as broken and somebody can fix. An account that vanishes is one nobody knows
 * to look for.
 *
 * Nullable because every existing user is staff and has no client, and because
 * the column is meaningless for them. A client row with this null is refused at
 * the controller rather than 500ing on a missing relation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('role')
                ->constrained()->nullOnDelete();

            // "Who is this client's login?" runs on the client screen, and
            // every client request resolves their own row.
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['client_id']);
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
