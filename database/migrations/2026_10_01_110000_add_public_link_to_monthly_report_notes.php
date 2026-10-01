<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A monthly report can now be sent as a link, not only as an attached PDF.
 *
 * WhatsApp only delivers a free-form file to someone who messaged the studio
 * in the last 24 hours; anyone else can only receive an approved template,
 * and a template can carry a link. So the report gets what invoices have: an
 * unguessable token (/r/{token}) that opens its PDF, and the sections that
 * were ticked when it was sent, so the link shows the report that was sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_report_notes', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('note');
            $table->json('shared_sections')->nullable()->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_report_notes', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'shared_sections']);
        });
    }
};
