<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Reel Transcriber's way to public reels: a Facebook-login token for the
 * studio's own Instagram business account, which Instagram's business
 * discovery accepts (the client accounts' Instagram-login tokens do not).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->text('meta_discovery_token')->nullable()->after('gemini_api_key');
            $table->string('meta_discovery_ig_id', 40)->nullable()->after('meta_discovery_token');
            $table->string('meta_discovery_username', 80)->nullable()->after('meta_discovery_ig_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['meta_discovery_token', 'meta_discovery_ig_id', 'meta_discovery_username']);
        });
    }
};
