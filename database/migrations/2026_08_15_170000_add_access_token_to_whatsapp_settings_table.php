<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The token that lets us send, as opposed to only receive.
 *
 * Kept apart from app_secret because they are opposites in the one way that
 * matters: the app secret only ever *verifies* something Meta sent us, while
 * this token *acts* as the studio -- anyone holding it can message any client
 * from the studio's number. Encrypted for that reason.
 *
 * api_version is stored rather than hard-coded because Meta retires Graph API
 * versions on a schedule, and the fix when it happens should be a dropdown on a
 * settings screen rather than a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->text('access_token')->nullable()->after('app_secret');
            $table->string('api_version', 10)->default('v22.0')->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn(['access_token', 'api_version']);
        });
    }
};
