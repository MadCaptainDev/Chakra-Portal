<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials for the competitor-reel-analysis pipeline. One row, same shape
 * as push_settings/notion_settings/whatsapp_settings.
 *
 * All three keys are encrypted -- unlike push_settings' asymmetric split
 * (service_account_json secret, web_config not), there is no "shipped to the
 * browser" half here: every one of these is a paid API credential that only
 * ever touches the server (Apify scraping, Gemini video analysis, the
 * Anthropic API), so all three get the same treatment as
 * WhatsappSetting::$access_token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_settings', function (Blueprint $table) {
            $table->id();
            $table->text('apify_token')->nullable();
            $table->text('gemini_api_key')->nullable();
            $table->text('anthropic_api_key')->nullable();
            // Not a secret -- just which Gemini model to call. gemini-2.0-flash
            // was retired; this stays overridable from the settings screen
            // rather than a code deploy the next time Google retires one.
            $table->string('gemini_model')->default('gemini-2.5-flash');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_settings');
    }
};
