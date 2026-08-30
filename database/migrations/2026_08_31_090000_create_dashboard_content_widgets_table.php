<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which content accounts each person has pinned to their dashboard.
     *
     * Per-user rather than a studio-wide setting: the producer running one
     * client's Instagram and the admin watching the whole studio want
     * different cards on the same screen, and a shared list means whoever
     * edits it last decides for everybody.
     *
     * A row per pin rather than a JSON column on users, matching how the
     * rest of this codebase stores lists -- it keeps the foreign key, so a
     * deleted content account cannot leave a dangling id behind that every
     * reader then has to defend against.
     */
    public function up(): void
    {
        Schema::create('dashboard_content_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_account_id')->constrained()->cascadeOnDelete();

            // The order the cards render in. Stored now even though the UI
            // only appends in picker order, so adding drag-to-reorder later
            // is a view change rather than another migration against a
            // table that by then has rows in it.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // One pin per account per person -- ticking the same account
            // twice is not a second card, it is the same card.
            $table->unique(['user_id', 'content_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_content_widgets');
    }
};
