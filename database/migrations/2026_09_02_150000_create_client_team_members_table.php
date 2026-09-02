<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a client's own portal shows them as "your team" -- their editor,
 * their account manager, whoever the studio wants a client to be able to
 * put a name to. A pivot rather than fixed columns on clients (account_
 * manager_id and friends): a client can have more than one editor, and a
 * free-text role label is more honest than a handful of named slots that
 * assume every client's team looks the same shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // "Editor", "Account Manager", "Producer" -- whatever the studio
            // wants the client to see this person as, not this app's own
            // internal role/permission vocabulary.
            $table->string('role')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_team_members');
    }
};
