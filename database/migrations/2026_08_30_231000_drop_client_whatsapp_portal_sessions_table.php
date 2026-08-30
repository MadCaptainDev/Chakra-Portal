<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('client_whatsapp_portal_sessions');
    }

    public function down(): void
    {
        // Recreate only if rolling back the whole feature — see 2026_08_30_230100 migration.
    }
};
