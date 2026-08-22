<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One uploaded backup file for one saas_products row.
 *
 * disk_path points into the 'local' filesystem disk (storage/app/private) --
 * genuinely outside the public web root, unlike App\Support\PublicUpload's
 * uploads (which have to live under public/uploads because storage:link
 * cannot run on this host). A database dump is exactly the kind of file that
 * must never be reachable by a guessed URL, so it does not go through that
 * path at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saas_product_id')->constrained()->cascadeOnDelete();

            $table->string('disk_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('original_filename')->nullable();
            // sha256 of the stored bytes -- a restore script can verify what
            // it downloaded matches what was actually uploaded.
            $table->string('checksum', 64);

            // What the ERP says the backup was taken at, not necessarily the
            // same moment as created_at (a slow upload, a retried request).
            $table->timestamp('taken_at');
            $table->timestamps();

            // The only two reads: this product's own backups, newest first,
            // and "how many does this product have" for the retention prune.
            $table->index(['saas_product_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_backups');
    }
};
