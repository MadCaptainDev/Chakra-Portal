<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A content account: one publishing identity belonging to a client, with
 * its own monthly video target.
 *
 * Not the same thing as a client, deliberately. SVA Silks runs two separate
 * accounts (silks and womenswear) that are planned and targeted
 * independently, so a target hung off the client alone could not express
 * what the studio actually commits to. Most clients have exactly one
 * account; the model costs nothing extra when they do.
 *
 * Also not the same as social_accounts, which is a live OAuth connection to
 * a real Instagram profile. This is a planning bucket -- it exists whether
 * or not anything is connected, and its members are Notion venture strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Nullable, not zero-default: "no target set" and "target of 0"
            // are different statements, and the dashboard says so.
            $table->unsignedSmallInteger('monthly_target')->nullable();
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_accounts');
    }
};
