<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            // Stamped by Script::markSentToClient() the moment a script
            // enters the client's one-at-a-time approval queue -- it is
            // what orders that queue (oldest sent first) and what the
            // portal screen reads to say how long a script has been
            // waiting on the client.
            $table->timestamp('sent_to_client_at')->nullable()->after('due_on');
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropColumn('sent_to_client_at');
        });
    }
};
