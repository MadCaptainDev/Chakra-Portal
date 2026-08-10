<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move avatars from the symlinked storage disk into public/uploads.
 *
 * Avatars were stored as "storage/avatars/x.jpg", which only resolves through
 * the public/storage symlink. Apache on this host refuses to follow it, so
 * every avatar returned 403 and no profile picture rendered anywhere.
 *
 * Copies rather than moves: if the copy fails the original is still there and
 * the row keeps pointing at it, which is no worse than the 403 it already
 * returned. Rows whose file is already missing are left alone rather than
 * rewritten to a path with nothing behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $source = storage_path('app/public/avatars');
        $target = public_path('uploads/avatars');

        if (! is_dir($target)) {
            @mkdir($target, 0755, true);
        }

        $rows = DB::table('users')
            ->whereNotNull('avatar_path')
            ->where('avatar_path', 'like', 'storage/avatars/%')
            ->get(['id', 'avatar_path']);

        foreach ($rows as $row) {
            $name = basename($row->avatar_path);
            $from = $source.'/'.$name;
            $to = $target.'/'.$name;

            if (! is_file($from)) {
                continue;
            }

            if (! is_file($to) && ! @copy($from, $to)) {
                continue;
            }

            DB::table('users')
                ->where('id', $row->id)
                ->update(['avatar_path' => 'uploads/avatars/'.$name]);
        }
    }

    /**
     * Points the rows back at the old location. The originals were never
     * deleted, so there is nothing to restore -- only the paths change.
     */
    public function down(): void
    {
        foreach (DB::table('users')->where('avatar_path', 'like', 'uploads/avatars/%')->get(['id', 'avatar_path']) as $row) {
            DB::table('users')
                ->where('id', $row->id)
                ->update(['avatar_path' => 'storage/avatars/'.basename($row->avatar_path)]);
        }
    }
};
