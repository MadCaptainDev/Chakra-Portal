<?php

use App\Support\Banks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The accounts EMIs are paid through: which bank (for its logo) and whose
     * account the month's money has to be deposited into. Replaces the free
     * text in expenses.payee, which stays filled with the account's label so
     * every screen and tool that reads it keeps working.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank', 32)->nullable();     // Banks::ALL key; null = paid to a person directly
            $table->string('holder_name')->nullable();  // whose account the money goes into
            $table->string('last4', 4)->nullable();
            $table->string('nickname')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('payee')->constrained()->nullOnDelete();
        });

        // Read every EMI's old text into an account; one account per bank+holder.
        $accounts = [];
        foreach (DB::table('expenses')->where('type', 'emi')->whereNotNull('payee')->where('payee', '!=', '')->get(['id', 'payee']) as $row) {
            [$bank, $holder] = Banks::parse($row->payee);
            $holder = $holder !== null ? ucwords(mb_strtolower($holder)) : null;
            $key = ($bank ?? '-').'|'.mb_strtolower((string) $holder);

            $accounts[$key] ??= DB::table('bank_accounts')->insertGetId([
                'bank' => $bank,
                'holder_name' => $holder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('expenses')->where('id', $row->id)->update(['bank_account_id' => $accounts[$key]]);
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
        Schema::dropIfExists('bank_accounts');
    }
};
