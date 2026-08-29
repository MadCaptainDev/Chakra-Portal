<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A broadcast: one approved Meta template, sent to everyone in one
 * phonebook.
 *
 * status walks draft -> scheduled -> sending -> completed, with failed and
 * cancelled as the two ways off that path. These are the exact strings
 * resources/views/components/badge.blade.php already knows how to colour, so
 * a screen built on top of this table gets a correct badge for free.
 *
 * batch_size and message_delay_ms exist because Meta throttles bursts --
 * sending is paced in batches rather than firing every message in the
 * phonebook at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // The Meta template this campaign sends. Not a foreign key --
            // templates live in Meta, not in this database -- so this is the
            // name/language pair WhatsappTemplateService resolves at send time.
            $table->string('meta_template_name');
            $table->string('meta_template_language')->default('en_US');

            $table->foreignId('phonebook_id')->constrained('whatsapp_phonebooks');

            // How a contact's var1..var5 map onto the template's {{1}}, {{2}}
            // ... positional parameters. Null means "use them in order".
            $table->json('variable_mapping')->nullable();

            $table->string('status')->default('draft');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedInteger('batch_size')->default(20);
            $table->unsignedInteger('message_delay_ms')->default(300);

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_campaigns');
    }
};
