<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp "EOD CHECK" checklists are gone -- Editor Updates reads what
 * people did on their to-dos instead. The table had no rows when it went.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('daily_checks');
    }

    public function down(): void
    {
        Schema::create('daily_checks', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('team', 80);
            $table->json('items');
            $table->string('status', 40)->nullable();
            $table->foreignId('reported_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('message');
            $table->timestamps();
            $table->unique(['date', 'team']);
        });
    }
};
