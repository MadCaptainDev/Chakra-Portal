<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes "Account Manager" from a free-text label on client_team_members
 * (see that migration -- role stays exactly what it was, a client-facing
 * name only) to a real, queryable flag. Nothing before this could ask "who
 * owns this client" without string-matching the role column, which is
 * fragile (a role of "Account Manager / Editor" or a typo silently answers
 * nobody). This is what depletion alerts and reassignment defaults key off
 * from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_team_members', function (Blueprint $table) {
            $table->boolean('is_account_manager')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('client_team_members', function (Blueprint $table) {
            $table->dropColumn('is_account_manager');
        });
    }
};
