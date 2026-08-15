<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The logins the studio holds on a client's behalf.
 *
 * Encrypted, not hashed, and that difference is the whole design. A password
 * this app owns is hashed because it only ever needs to be *checked*; these
 * have to be read back and typed into Instagram, so they are encrypted with
 * APP_KEY and are recoverable by anything that holds it. That is a real
 * exposure and it is accepted deliberately -- the alternative is a shared
 * spreadsheet, which is the same exposure with no access control and no record
 * of who looked.
 *
 * What follows from that:
 *
 * - APP_KEY is now as valuable as the database. A backup containing both is a
 *   backup of every client's social accounts.
 * - `username` is left in the clear on purpose. An Instagram handle is public
 *   and a Gmail address is semi-public, and having them readable means the
 *   screen can list accounts without decrypting anything.
 * - `secret` and `notes` are encrypted. notes especially: it is where recovery
 *   codes and 2FA backups end up whether or not anyone intended it to.
 * - Nothing here is searchable, and that is correct. An index on a credential
 *   is a way to confirm a guess without ever being granted access.
 *
 * client_credential_views is the part that makes shared credentials
 * defensible: every reveal is written down, so "who had this password in
 * March" has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // instagram | youtube | google | facebook | other
            $table->string('kind', 20);
            // "Main account", "Reels backup" -- one client can have several of
            // the same kind and needs to tell them apart.
            $table->string('label')->nullable();

            $table->string('username')->nullable();
            $table->text('secret')->nullable();
            $table->text('notes')->nullable();
            $table->string('url')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The only way this table is ever read: one client's list.
            $table->index(['client_id', 'kind']);
        });

        Schema::create('client_credential_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_credential_id')->constrained()->cascadeOnDelete();
            /*
             * The viewer is NOT nullOnDelete. Deleting an account must not
             * quietly erase the record of what it looked at -- that is exactly
             * the row somebody would want gone.
             */
            $table->foreignId('user_id')->constrained();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('viewed_at');

            $table->index(['client_credential_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_credential_views');
        Schema::dropIfExists('client_credentials');
    }
};
