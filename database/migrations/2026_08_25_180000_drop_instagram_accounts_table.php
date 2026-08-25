<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Routines fan out against Client Instagram rows (social_accounts), not a
 * separate free-floating handles table. Drop the redundant master list.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Orphan morph rows pointed at the dropped table — remove, do not remap
        // (IDs on social_accounts are unrelated to the old master list).
        if (Schema::hasTable('routine_subjects')) {
            DB::table('routine_subjects')->where('subject_type', 'instagram_account')->delete();
        }

        if (Schema::hasTable('routine_occurrences')) {
            DB::table('routine_occurrences')->where('subject_type', 'instagram_account')->delete();
        }

        if (Schema::hasTable('routines')) {
            DB::table('routines')
                ->where('subject_type', 'instagram_account')
                ->update(['subject_type' => null]);
        }

        Schema::dropIfExists('instagram_accounts');
    }

    public function down(): void
    {
        if (Schema::hasTable('instagram_accounts')) {
            return;
        }

        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('handle');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('handle');
            $table->index('client_id');
            $table->index('is_active');
        });
    }
};
