<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Approve for everyone": an OK given on another approver's behalf records who gave it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_approvals', function (Blueprint $table) {
            $table->foreignId('by_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('review_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('by_user_id');
        });
    }
};
