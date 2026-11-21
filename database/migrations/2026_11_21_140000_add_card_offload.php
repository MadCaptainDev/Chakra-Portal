<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card offload from Chakra Planner: the Mac app reads a camera card, matches
 * its clips to the videos the crew logged, and copies them into the client's
 * pending folder (see docs/chakra-planner/API.md, "Card offload").
 *
 * - clients: where that client's footage goes. The drive is not consistent
 *   ("Nov - 2026/pending", "Pending", "NOV- 2026/PENDING"), so the folder
 *   under the root is a template rather than a fixed rule.
 * - cameras: each body by its serial, with how far its clock is off, so a
 *   camera set to the wrong date still lines up with the crew's log.
 * - shoot_videos.started_at: when the crew pressed "Start take", so a clip
 *   can be matched to its take exactly instead of guessed from time gaps.
 * - shoots.take_started_at: the take rolling right now, until it is filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('drive_root', 500)->nullable();
            $table->string('folder_prefix', 120)->nullable();
            $table->string('pending_path_template', 255)->nullable();
        });

        Schema::create('cameras', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 64)->unique();
            $table->string('name', 120)->nullable();
            $table->integer('clock_offset_seconds')->default(0);
            $table->timestamps();
        });

        Schema::table('shoot_videos', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
        });

        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('take_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shoots', fn (Blueprint $table) => $table->dropColumn('take_started_at'));
        Schema::table('shoot_videos', fn (Blueprint $table) => $table->dropColumn('started_at'));
        Schema::dropIfExists('cameras');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['drive_root', 'folder_prefix', 'pending_path_template']);
        });
    }
};
