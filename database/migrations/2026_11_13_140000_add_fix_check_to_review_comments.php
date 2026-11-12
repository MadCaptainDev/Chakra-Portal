<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A change asked on one version, checked on the next: did the editor
 * really fix it? confirmed, or not_fixed (which raises it again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_comments', function (Blueprint $table) {
            $table->string('fix_state', 20)->nullable();
            $table->foreignId('fix_checked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fix_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('review_comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fix_checked_by_id');
            $table->dropColumn(['fix_state', 'fix_checked_at']);
        });
    }
};
