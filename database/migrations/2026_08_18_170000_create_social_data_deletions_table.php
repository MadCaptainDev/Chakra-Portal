<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data deletion requests, as Meta requires them.
 *
 * When somebody removes the app from their Instagram account, Meta POSTs to
 * the Data Deletion Request URL and expects a JSON reply naming a confirmation
 * code and a URL where the person can check what happened. That URL has to
 * keep working afterwards, which is the whole reason this table exists: the
 * data is gone by then, so there would otherwise be nothing left to look up.
 *
 * The row is the receipt, not the data. It holds what was asked and when it
 * was honoured -- never the token, and not the account's content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_data_deletions', function (Blueprint $table) {
            $table->id();

            $table->string('platform', 20);
            // Whose data. Kept because the status page has to answer for a
            // specific request, and because "did we honour that" is a question
            // worth being able to answer a year later.
            $table->string('platform_user_id')->nullable();

            // What Meta is told to quote back. Unique because the status URL
            // is looked up by it alone.
            $table->string('confirmation_code', 40)->unique();

            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            // Plain English, for the status page.
            $table->string('outcome', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_data_deletions');
    }
};
