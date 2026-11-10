<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shoot day, past "wrapped".
 *
 * Until now the record stopped at finished_at: nothing said where the crew
 * went next, whether the footage reached a drive, or when a shoot was called
 * off. This adds those facts, and a shoot_events log so the day can be read
 * back in order -- the five timestamp pairs on shoots answer "when", but not
 * "what happened, in what order, and who said so".
 *
 * Only adds. The existing timestamps stay the source for isInProgress() and
 * friends; the log is copied from them once, below, so shoots that already
 * ran have a timeline too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shoot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shoot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('note', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['shoot_id', 'created_at']);
        });

        Schema::table('shoots', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();

            // An admin letting a shoot leave without its kit ticked, and why.
            $table->timestamp('kit_waived_at')->nullable();
            $table->foreignId('kit_waived_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kit_waived_reason', 500)->nullable();

            $table->string('footage_drive', 60)->nullable();
            $table->string('footage_url', 1000)->nullable();
            $table->timestamp('footage_filed_at')->nullable();
            $table->foreignId('footage_filed_by_id')->nullable()->constrained('users')->nullOnDelete();

            // One per kind of lateness, so each is told to the admins once.
            $table->timestamp('late_start_alert_at')->nullable();
            $table->timestamp('overrun_alert_at')->nullable();
            $table->timestamp('footage_alert_at')->nullable();
            $table->timestamp('kit_alert_at')->nullable();
        });

        Schema::table('shoot_crew', function (Blueprint $table) {
            // What this person did after the wrap: next_shoot or office.
            $table->string('after_wrap', 20)->nullable();
            $table->foreignId('next_shoot_id')->nullable()->constrained('shoots')->nullOnDelete();
            $table->timestamp('returning_at')->nullable();
            $table->timestamp('back_at_office_at')->nullable();
        });

        Schema::table('shoot_videos', function (Blueprint $table) {
            $table->timestamp('footage_copied_at')->nullable();
        });

        $this->backfill();
    }

    /** Shoots that ran before the log existed get their day written into it. */
    private function backfill(): void
    {
        $steps = [
            'departed' => ['departed_at', 'departed_by_id'],
            'reached' => ['reached_at', 'reached_by_id'],
            'started' => ['started_at', 'started_by_id'],
            // Nobody was recorded as the one who wrapped.
            'wrapped' => ['finished_at', null],
        ];

        DB::table('shoots')->whereNotNull('departed_at')->orWhereNotNull('reached_at')->orWhereNotNull('started_at')
            ->orderBy('id')->chunk(200, function ($shoots) use ($steps) {
                $rows = [];
                foreach ($shoots as $shoot) {
                    foreach ($steps as $type => [$at, $by]) {
                        if ($shoot->{$at}) {
                            $rows[] = [
                                'shoot_id' => $shoot->id,
                                'user_id' => $by ? $shoot->{$by} : null,
                                'type' => $type,
                                'note' => null,
                                'meta' => null,
                                'created_at' => $shoot->{$at},
                            ];
                        }
                    }
                }
                if ($rows) {
                    DB::table('shoot_events')->insert($rows);
                }
            });

        DB::table('shoot_videos')->orderBy('id')->chunk(500, function ($videos) {
            DB::table('shoot_events')->insert($videos->map(fn ($v) => [
                'shoot_id' => $v->shoot_id,
                'user_id' => $v->recorded_by_id,
                'type' => 'video',
                'note' => mb_substr((string) $v->name, 0, 500),
                'meta' => json_encode(['video_id' => $v->id]),
                'created_at' => $v->created_at,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::table('shoot_videos', fn (Blueprint $table) => $table->dropColumn('footage_copied_at'));

        Schema::table('shoot_crew', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_shoot_id');
            $table->dropColumn(['after_wrap', 'returning_at', 'back_at_office_at']);
        });

        Schema::table('shoots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropConstrainedForeignId('kit_waived_by_id');
            $table->dropConstrainedForeignId('footage_filed_by_id');
            $table->dropColumn([
                'cancelled_at', 'cancel_reason', 'kit_waived_at', 'kit_waived_reason',
                'footage_drive', 'footage_url', 'footage_filed_at',
                'late_start_alert_at', 'overrun_alert_at', 'footage_alert_at', 'kit_alert_at',
            ]);
        });

        Schema::dropIfExists('shoot_events');
    }
};
