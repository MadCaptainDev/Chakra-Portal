<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Studio-managed Instagram handles used as routine subjects.
 *
 * Separate from social_accounts (OAuth) and content_accounts (Notion
 * planning buckets): this is a lightweight master list of handles the
 * studio owes daily duties against, optionally linked to a Client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('handle');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('handle');
            $table->index('client_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
