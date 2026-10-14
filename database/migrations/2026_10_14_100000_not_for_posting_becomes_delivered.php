<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * My Editing's "Not for posting" becomes "Delivered": a reviewed video either
 * goes out as a Reel/Post/Story or is handed to the client. The old rows were
 * finished and out, so they are delivered as of when they were last touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('todos')->where('kind', 'none')->update([
            'kind' => 'delivered',
            'posted_at' => DB::raw('COALESCE(posted_at, updated_at)'),
        ]);
    }

    public function down(): void
    {
        DB::table('todos')->where('kind', 'delivered')->update(['kind' => 'none']);
    }
};
