<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram's profile picture URL does not fit in 500 characters.
 *
 * It is a signed CDN URL carrying a token, a content hash and cache
 * parameters as query string -- the one that broke this column ran past 600.
 * Every real connection attempt will have one this long; it is the normal
 * shape of the value, not an outlier to guard against.
 *
 * Found by the first real connection: thechakra_productions authorised
 * cleanly and the INSERT was rejected by MySQL, rolling back a transaction
 * that had already succeeded against Instagram. TEXT has no practical limit
 * this value will ever reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->text('profile_picture_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('profile_picture_url', 500)->nullable()->change();
        });
    }
};
