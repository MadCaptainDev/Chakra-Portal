<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money the studio pays out that belongs to a client -- Meta Ads spend, API
 * subscriptions, a model's fee -- and expects back.
 *
 * Deliberately NOT a fifth `expenses.type`. That table is "everything the
 * business pays out monthly" and its overview screen exists to state total
 * outflow correctly; an advance is not outflow, because it returns. Folding
 * these in would overstate the studio's costs on the one screen whose job is
 * to get them right, and would leave client_id, billable_amount and
 * recovered_at null on every EMI, salary and bill row ever written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()
                ->constrained('taxonomy_terms')->nullOnDelete();
            $table->string('name');
            $table->string('payee')->nullable();

            // What actually left the studio's pocket.
            $table->decimal('amount', 12, 2);

            /*
             * What the client is charged, when that differs -- sometimes at
             * cost, sometimes with a margin. Nullable rather than defaulted to
             * `amount`: "bill it at cost" is then a stored fact rather than a
             * copied number that silently stops matching when a mistyped
             * amount is corrected later.
             */
            $table->decimal('billable_amount', 12, 2)->nullable();

            $table->date('spent_on')->index();

            // Private: read back through an authenticated route, never from
            // public/uploads -- see ClientAdvanceController::receipt().
            $table->string('receipt_path')->nullable();

            $table->timestamp('recovered_at')->nullable();
            $table->string('recovered_note')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Every screen asks the same question: what does this client still
            // owe me.
            $table->index(['client_id', 'recovered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_advances');
    }
};
