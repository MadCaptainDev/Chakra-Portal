<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            // The Notion-synced content item (Reel/Post/Short) this script
            // was written for -- nullable and nullOnDelete like every other
            // relation on this table, same reasoning: a content item can be
            // re-synced away or deleted without taking the writing with it.
            // Set by the Google Keep bulk importer (matched by title); a
            // script created the ordinary way through the Scripts screen
            // simply never has one.
            $table->foreignId('content_item_id')->nullable()->after('client_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_item_id');
        });
    }
};
