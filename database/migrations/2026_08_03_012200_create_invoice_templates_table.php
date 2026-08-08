<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Default');
            // blocks = drag-and-drop layout; html = free-form HTML body
            $table->string('mode', 16)->default('blocks');
            $table->json('blocks')->nullable();
            $table->longText('html')->nullable();
            $table->longText('custom_css')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_templates');
    }
};
