<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A month of paid-ads results for one client, kept exactly as the report was
 * produced.
 *
 * The body is one JSON document rather than a dozen tables. The report has a
 * fixed shape (see App\Services\AdReports\AdReportImporter) and is only ever
 * read back whole -- nobody queries "all campaigns named X" across clients --
 * and a ledger-style table per section would mean a migration every time the
 * producer adds a field to the report. What IS queried (which client, which
 * month, which platform) is lifted into real columns so the list can be found
 * and one month cannot be imported twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_reports', function (Blueprint $table) {
            $table->id();

            // Nullable and nullOnDelete: the report is a record of what was
            // spent, and outliving a deleted client is better than taking it
            // with them. Linked by id on purpose -- the report names the
            // client as free text, and "Thillai Pet Clinic" is not "Thillai
            // Pets Clinic".
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            $table->string('platform', 40)->default('Meta Ads');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('title');
            $table->json('data');

            // The link. Same rule as proposals: the token in the path is the
            // only credential, so it is long and random, and null means the
            // link has been switched off.
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Importing September twice updates September.
            $table->unique(['client_id', 'platform', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_reports');
    }
};
