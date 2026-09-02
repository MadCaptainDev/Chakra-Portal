<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Null for every shoot the studio books itself -- set only when a client
 * asked for this one through their own portal (Client\ShootRequestController),
 * so the Shoots board can tell "we planned this" from "somebody is waiting
 * on us to triage this" without reinterpreting what `status` already means
 * for a studio-created shoot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('requested_at')->nullable()->after('created_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('shoots', function (Blueprint $table) {
            $table->dropColumn('requested_at');
        });
    }
};
