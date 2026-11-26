<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelling a video or moving it in the planner is an admin's decision:
 * when an editor does it, it becomes a request here until an admin
 * approves (then it happens) or refuses. App\Support\VideoRequests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_requests', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // cancel, move, status
            $table->foreignId('todo_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->json('payload')->nullable(); // note, from, to, status
            $table->string('status', 12)->default('pending'); // pending, approved, refused, failed
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_requests');
    }
};
