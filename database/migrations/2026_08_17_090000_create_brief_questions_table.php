<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions the studio adds to the brief itself, without a deploy.
 *
 * These sit ALONGSIDE the catalogue in App\Support\BrandBrief rather than
 * replacing it. That split is the whole design:
 *
 * - The code catalogue is the core the product depends on. WRITER_KEYS names
 *   some of it, the script drawer reads it, and its wording was argued over.
 *   Nobody should be able to delete "Briefly describe your business" from a
 *   settings screen at 11pm.
 * - This table is everything the studio thinks of afterwards. It changes
 *   often, it is nobody's dependency, and waiting on a developer for it is
 *   exactly the friction that ends with the question asked over WhatsApp and
 *   the answer living in one person's chat history.
 *
 * `key` is the stable identity and is generated once from the label, never
 * from the label thereafter. Answers are keyed by it, so renaming a question
 * keeps every answer attached -- which is the behaviour somebody fixing a typo
 * expects, and the opposite of what a label-derived key would do.
 *
 * Questions are archived, never deleted. A row here has client answers hanging
 * off it in client_brief_answers, and hard-deleting the question would leave
 * them orphaned and invisible -- the studio's own record of what a client said,
 * thrown away to tidy a list. is_active hides it from new briefs while the
 * answers already given stay readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brief_questions', function (Blueprint $table) {
            $table->id();

            /*
             * Which group it appears under. A plain string matching a step id
             * in BrandBrief::STEPS, not a foreign key -- the steps live in
             * code, so there is no table to point at. A question whose step
             * has since been renamed falls back to the last group rather than
             * disappearing; see BrandBrief::customQuestions().
             */
            $table->string('step_id', 40);

            // Stable, unique, and never regenerated. This is what answers are
            // keyed by -- see the note above.
            $table->string('key', 60)->unique();

            // textarea | text | chips | checks -- the types a person can
            // usefully define without also designing validation for it. urls
            // and contact stay code-only: they carry their own field layout.
            $table->string('type', 20);

            $table->string('label', 255);
            $table->string('help', 255)->nullable();

            // For chips/checks. Null for the written types.
            $table->json('options')->nullable();

            $table->boolean('multi')->default(false);
            $table->boolean('required')->default(false);

            /*
             * Custom questions sort after the code ones within their group,
             * and among themselves by this. Kept sparse (10, 20, 30…) so a
             * question can be dropped between two others without renumbering
             * the rest.
             */
            $table->unsignedInteger('sort_order')->default(0);

            // Archived rather than deleted. Answers survive and stay readable.
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The only read: every live question, grouped and in order.
            $table->index(['is_active', 'step_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brief_questions');
    }
};
