<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we ask a client before anybody writes a word for them.
 *
 * The questions are not in this schema, and that is the design. They live in
 * App\Support\BrandBrief as a constant, because a studio's discovery questions
 * change about twice a year and a table of questions would mean a screen to
 * edit them, a seeder to fill them, and an answer to "what happens to thirty
 * clients' answers when somebody rewords question nine". A constant is
 * versioned by git and has none of those problems. App\Support\Permission and
 * App\Models\TaxonomyTerm already make this argument for their own lists.
 *
 * What *is* in the schema is the answers, one row per question, because the
 * whole point of collecting this in the portal rather than a Google Form is
 * being able to ask "which clients said Product launch" with a WHERE.
 *
 * The parts worth knowing before changing anything here:
 *
 * - The unique on (client_brief_id, question_key) is what makes the save an
 *   upsert instead of a delete-and-reinsert. Delete-and-reinsert would throw
 *   away every answer's updated_at, and those are what tell a writer that the
 *   client changed their tone words after the script was started.
 * - `value` carries no foreign key to taxonomy_terms even though it often
 *   holds a term id. Term ids are validated on the way in against their own
 *   type, but not constrained: retiring "Hospitality" must not cascade a
 *   client's answer out of existence.
 * - Two value columns, not one. They are mutually exclusive per question type,
 *   so the branch lives in one accessor rather than at every call site. Single
 *   answers stay in `value` as plain text so a report stays a plain WHERE;
 *   multi-selects are rare to report on and can take the JSON hit.
 * - status is stored, progress is not. A stored percentage would mean a
 *   migration every time a question is added; counting against the catalogue
 *   at read time costs nothing at this size.
 * - Nothing writes on a GET. There is no row until the client saves, so a
 *   staff member opening a client record cannot create one.
 * - Answers whose question has since been deleted from the catalogue are left
 *   alone deliberately. The render loop iterates the catalogue, not the rows,
 *   so they are invisible and harmless -- and still there if the question
 *   comes back. Cleaning them up would throw away the one copy of something a
 *   client sat down and typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_briefs', function (Blueprint $table) {
            $table->id();

            // One brand, one brief. The unique is the constraint that makes
            // Client::brief() a hasOne rather than a hasMany nobody wanted.
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();

            // not_started | in_progress | submitted
            $table->string('status', 20)->default('not_started');

            /*
             * The FIRST submission, and it never moves. A client may keep
             * editing afterwards -- brands change -- and locking the form only
             * means the change arrives by email and the portal's copy goes
             * stale. Per-answer updated_at records everything after this.
             */
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // "Who still owes us a brief" is the only cross-client question
            // anyone asks of this table.
            $table->index('status');
        });

        Schema::create('client_brief_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_brief_id')->constrained()->cascadeOnDelete();

            // A key from App\Support\BrandBrief::QUESTIONS. Unknown keys are
            // dropped before they reach here; see ClientBriefRequest.
            $table->string('question_key', 60);

            // Text, url, number, a single choice key, or a single term id.
            $table->text('value')->nullable();
            // Multi-selects only: a list of term ids or option keys.
            $table->json('value_json')->nullable();

            $table->timestamps();

            $table->unique(['client_brief_id', 'question_key']);
            // "Which clients answered X" reads across briefs by key.
            $table->index('question_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_brief_answers');
        Schema::dropIfExists('client_briefs');
    }
};
