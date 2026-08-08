<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Project enquiries submitted from the public landing page.
     *
     * The row is written before the notification is attempted, so a lead
     * survives a dead SMTP host: production runs MAIL_MAILER=log with
     * LOG_LEVEL=error, which silently discards the mail record entirely.
     */
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('project')->nullable();
            $table->text('message');

            // Kept for spam triage, not for tracking anyone.
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            // The inbox lists newest first and badges the unread count.
            $table->index('created_at');
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
