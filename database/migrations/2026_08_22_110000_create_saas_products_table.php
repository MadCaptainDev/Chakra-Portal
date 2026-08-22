<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One client-built, Chakra-maintained piece of software -- DJ Thangamaaligai's
 * ERP is the first. Owns a bearer token (backups and license checks
 * authenticate against it, never a session -- see mcp_tokens for the same
 * shape and the reasoning behind hashing rather than encrypting it) and the
 * AMC (Annual Maintenance Contract) lifecycle that keeps it running.
 *
 * is_suspended is a real, deliberate divergence from this app's usual stance:
 * ClientLoginController::destroy() argues against soft-disable flags and
 * deletes a login outright rather than marking it inactive, specifically so
 * no other query has to remember a suspended state exists. A product's
 * status is a different problem -- a license entitlement's lifecycle, not
 * whether an account exists -- and deleting it on non-payment would destroy
 * its backup history, which is the one thing this whole feature exists to
 * keep safe. Scoped to this table alone, on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Nullable because a product is created first (name, client) and
            // only gets a token issued as a second step -- the same
            // create-then-issue flow McpTokenController already uses, so the
            // one-time plaintext token can be flashed right after creation
            // instead of being generated blind before the product exists.
            $table->string('token_hash', 64)->nullable()->unique();

            // How many backup versions to keep before the oldest is pruned on
            // the next upload -- see SaasBackupController, which prunes
            // synchronously rather than on a schedule (no reliable cron here).
            $table->unsignedInteger('backup_retention_count')->default(20);

            // Null means never billed yet. Compared against today() to
            // compute status() fresh on every read -- never cached, same
            // reasoning as PushSetting's own decoded-fresh methods.
            $table->date('amc_paid_until')->nullable();

            $table->boolean('is_suspended')->default(false);
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('suspended_by_id')->nullable()->constrained('users')->nullOnDelete();

            // Set once AMC billing (phase 3) is wired up; null until then.
            $table->foreignId('recurring_invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_products');
    }
};
