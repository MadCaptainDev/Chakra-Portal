<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions asked of one client only.
 *
 * The studio's brief is mostly the same for everybody, and then there is the
 * bridal makeup artist whose brief needs fifteen questions about her craft
 * that would be nonsense on a jeweller's. Those questions are not a different
 * brief -- she still answers the seven standard groups -- they are an extra
 * group only she is shown.
 *
 * client_id null means every client, which is what every existing row already
 * means, so nothing needs backfilling.
 *
 * The catalogue becomes client-aware with this, and that is the part to be
 * careful about: BrandBrief is static and request-cached, so it now has to be
 * told which client it is assembling for. See BrandBrief::forClient().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brief_questions', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();

            /*
             * The tab these appear under. Only used by client-specific
             * questions -- a global question goes into one of the seven code
             * groups, which already have labels. Nullable so the shared rows
             * are unaffected.
             */
            $table->string('group_label')->nullable()->after('step_id');

            // The read is always "this client's questions plus everybody's".
            $table->index(['client_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('brief_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('group_label');
        });
    }
};
