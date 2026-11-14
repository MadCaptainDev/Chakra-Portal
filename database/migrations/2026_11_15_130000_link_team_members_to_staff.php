<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The public Team page's people, linked to their staff login where they
     * have one, and prefilled -- HIDDEN -- from the staff: name, portal photo,
     * and the job title on their salary record (titles only, nothing else).
     * Nobody shows on the website until someone switches them on.
     */
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        if (DB::table('team_members')->exists()) {
            return; // people were added by hand: leave them be
        }

        $rank = ['ceo' => 1, 'cxo' => 2, 'coo' => 3, 'cto' => 4, 'editor' => 5, 'jr editor' => 6, 'content writer' => 7];
        $rows = DB::table('expenses')->where('type', 'salary')->where('is_active', true)->get(['name', 'role', 'user_id']);
        $order = 0;
        foreach ($rows->sortBy(fn ($r) => [$rank[strtolower((string) $r->role)] ?? 50, $r->name]) as $r) {
            $user = $r->user_id ? DB::table('users')->where('id', $r->user_id)->first(['id', 'name', 'avatar_path']) : null;
            DB::table('team_members')->insert([
                'user_id' => $user?->id,
                'name' => $user?->name ?? $r->name,
                'role' => $r->role,
                'photo_path' => $user?->avatar_path && is_file(public_path($user->avatar_path)) ? $user->avatar_path : null,
                'sort_order' => ++$order,
                'is_visible' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
