<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is going, what is going with them, and what is being shot.
 *
 * shoot_kit is the checklist -- the thing this whole feature was asked for. It
 * carries its own columns rather than being a bare pivot, because the
 * interesting facts live on the relationship: how many went, who ticked it off,
 * whether it came back.
 *
 * The four custody columns are written only by the check-out and check-in
 * actions and are kept out of $fillable on the model. A form post must not be
 * able to claim that somebody else packed the van.
 *
 * condition is how an item stays out of the free pool after a shoot: something
 * flagged missing or damaged is not available to the next one, which is the
 * entire point of tracking returns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_crew', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable();
            // Not everyone arrives at the call time -- sound often comes later.
            $table->time('call_time')->nullable();
            $table->timestamps();

            $table->unique(['shoot_id', 'user_id']);
        });

        Schema::create('shoot_kit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);

            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('checked_out_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * How many actually came back. Without it a line is all-or-nothing
             * and "four batteries went, three came back" cannot be recorded --
             * which is the one thing a returnable-stock system has to be able
             * to say. The shortfall keeps counting against availability until
             * somebody resolves it.
             */
            $table->unsignedInteger('returned_quantity')->nullable();

            $table->string('condition', 20)->nullable();
            $table->text('condition_note')->nullable();
            $table->timestamps();

            // One line per item per shoot; taking two of something is a
            // quantity, not a second row.
            $table->unique(['shoot_id', 'equipment_item_id']);

            // The availability query: everything unreturned, by item.
            $table->index(['equipment_item_id', 'returned_at']);
        });

        Schema::create('shoot_script', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('script_id')->constrained()->cascadeOnDelete();

            $table->unique(['shoot_id', 'script_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shoot_script');
        Schema::dropIfExists('shoot_kit');
        Schema::dropIfExists('shoot_crew');
    }
};
