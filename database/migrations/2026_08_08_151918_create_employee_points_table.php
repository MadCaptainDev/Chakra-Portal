<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A monthly score an admin awards an employee, with an optional remark.
     * One row per person per month.
     */
    public function up(): void
    {
        Schema::create('employee_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period');                 // always the 1st of the month
            $table->unsignedSmallInteger('points')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_points');
    }
};
