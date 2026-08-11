<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The blocks a script is written in -- Hook, Body, CTA, or whatever the writer
 * names them.
 *
 * Sections rather than one big text field because that is how these scripts
 * are actually used: a hook gets rewritten on its own, a CTA gets reordered to
 * the top, a voice-over note sits beside the dialogue it belongs to. A single
 * textarea can express none of that.
 *
 * heading is free text with no lookup table behind it. Writers invent section
 * names -- "Macro insert", "Tamil VO" -- and a closed list would only teach
 * them to type the real name into the body instead.
 *
 * body holds sanitised HTML from the editor, never raw input. See
 * App\Support\Html: everything is filtered server-side on the way in, because
 * a contenteditable will cheerfully hand over a <script> tag if pasted one.
 *
 * version is the autosave's conflict check. Saves are per section, so two
 * people working on different parts of the same script never collide; a save
 * against a stale version is refused rather than merged, and the writer is
 * asked which copy wins. Nothing is ever silently overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->constrained()->cascadeOnDelete();
            $table->string('heading');
            $table->longText('body')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            // The editor loads every section of one script in written order.
            $table->index(['script_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_sections');
    }
};
