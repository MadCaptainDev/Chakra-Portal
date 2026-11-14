<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The public website's own visitor numbers (there were none: no
     * analytics, and this host keeps no access logs). Anonymous: a random id
     * kept in the visitor's browser, never a name, IP or anything typed.
     * Staff (signed in, or ever signed in on that browser) and bots are left out.
     */
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->char('visitor', 16)->index();      // random, kept in the browser
            $table->char('session', 16)->index();      // one sitting
            $table->string('page', 160);                // path, e.g. /portfolio
            $table->string('source', 24)->index();      // instagram | google | whatsapp | facebook | direct | other | (utm source)
            $table->string('referrer_host', 120)->nullable();
            $table->string('utm_campaign', 80)->nullable();
            $table->string('device', 8);                // phone | tablet | computer
            $table->boolean('first_visit')->default(false);
            $table->timestamp('created_at')->index();
        });

        Schema::create('site_events', function (Blueprint $table) {
            $table->id();
            $table->char('visitor', 16)->index();
            $table->char('session', 16)->index();
            $table->string('page', 160);
            $table->string('type', 24)->index();        // whatsapp | play | case_study | section | scroll | category | more | call | enquiry
            $table->string('target', 160)->nullable();  // what: a piece id, a section, a choice
            $table->string('label', 200)->nullable();   // readable: the piece's title, the message chosen
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_events');
        Schema::dropIfExists('site_visits');
    }
};
