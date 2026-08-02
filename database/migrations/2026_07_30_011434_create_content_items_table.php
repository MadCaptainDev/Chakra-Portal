<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('notion_page_id')->unique();
            $table->string('notion_url')->nullable();
            $table->string('title')->nullable();
            $table->string('venture')->nullable();
            $table->string('status')->nullable();
            $table->date('published_date')->nullable();
            $table->date('shoot_date')->nullable();
            $table->string('editor')->nullable();
            $table->timestamp('notion_created_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('venture');
            $table->index('source');
            $table->index('published_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
