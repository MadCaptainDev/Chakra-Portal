<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The studio-authored "month in one paragraph" text on a client's monthly
 * report. Not derived from Instagram at all -- a human writes it, one row
 * per client per calendar month, so August's note and July's note don't
 * overwrite each other when staff revisit an old report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_report_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('month'); // first-of-month, e.g. 2026-07-01
            $table->text('note')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_report_notes');
    }
};
