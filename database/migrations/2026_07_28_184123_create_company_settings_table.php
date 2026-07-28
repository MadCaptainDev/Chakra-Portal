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
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('Chakra Productions');
            $table->string('logo_path')->nullable();
            $table->string('address')->nullable();
            $table->string('signature_name')->default('Annamalai Sivakumar');
            $table->string('signature_title')->default('CEO');
            $table->string('invoice_prefix')->default('CP-');
            $table->string('footer_text')->default('ThankYou For Your Buisness With Us !');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
