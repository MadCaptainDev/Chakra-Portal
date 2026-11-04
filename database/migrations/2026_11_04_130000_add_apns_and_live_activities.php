<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Direct Apple push (APNs, token auth with a .p8 key) for the iPhone app:
 * regular notifications to APNs device tokens, and Live Activities
 * (push-to-start and update tokens). Plus where each crew member is on a
 * shoot day, for the CrewTrip activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_settings', function (Blueprint $table) {
            $table->text('apns_key_p8')->nullable();
            $table->string('apns_key_id', 20)->nullable();
            $table->string('apns_team_id', 20)->nullable();
            $table->string('apns_bundle_id', 120)->nullable();
        });

        Schema::table('push_tokens', function (Blueprint $table) {
            $table->string('environment', 12)->nullable();
        });

        Schema::create('live_activity_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('activity', 32);
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('activity_id', 120)->nullable();
            $table->foreignId('shoot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 12)->default('production');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('failure_reason', 120)->nullable();
            $table->timestamps();

            $table->index(['activity', 'type', 'user_id']);
        });

        Schema::table('shoot_crew', function (Blueprint $table) {
            $table->string('trip_status', 16)->nullable();
            $table->timestamp('trip_eta_at')->nullable();
            $table->decimal('trip_lat', 10, 7)->nullable();
            $table->decimal('trip_lng', 10, 7)->nullable();
            $table->timestamp('trip_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shoot_crew', fn (Blueprint $t) => $t->dropColumn(['trip_status', 'trip_eta_at', 'trip_lat', 'trip_lng', 'trip_updated_at']));
        Schema::dropIfExists('live_activity_tokens');
        Schema::table('push_tokens', fn (Blueprint $t) => $t->dropColumn('environment'));
        Schema::table('push_settings', fn (Blueprint $t) => $t->dropColumn(['apns_key_p8', 'apns_key_id', 'apns_team_id', 'apns_bundle_id']));
    }
};
