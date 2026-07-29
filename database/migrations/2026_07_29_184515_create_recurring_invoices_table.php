<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('label')->nullable();
            $table->text('intro_text')->nullable();
            $table->string('discount_label')->nullable();
            $table->decimal('discount_amount', 12, 2)->nullable();
            $table->string('frequency');
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->date('next_run_on')->index();
            $table->date('last_generated_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
