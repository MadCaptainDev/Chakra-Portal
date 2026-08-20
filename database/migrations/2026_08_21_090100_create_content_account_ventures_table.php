<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which raw Notion venture strings belong to which content account.
 *
 * An explicit mapping table rather than fuzzy string matching at read time.
 * TimesheetVenture::normalize() already does the fuzzy version, and on real
 * data it produced genuine misattributions -- "thinkwithpriya" matched Riya
 * because "p(riya)" contains it, and "SVA Golds and Diamonds" matched SVA
 * Silks on the "SVA" token despite SVA Gold and Diamonds being its own
 * client. A dashboard that counts deliverables against targets cannot be
 * built on a matcher that is confidently wrong; every venture here was put
 * where it is by a person.
 *
 * venture is unique across the whole table: one Notion venture belongs to
 * exactly one account, so a video is never counted twice.
 *
 * A venture with no row here is UNMAPPED and is surfaced on the mapping
 * screen rather than silently dropped -- see ContentAccount::unmappedVentures().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_account_ventures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_account_id')->constrained()->cascadeOnDelete();
            $table->string('venture')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_account_ventures');
    }
};
