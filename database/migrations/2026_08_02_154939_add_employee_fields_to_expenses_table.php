<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salary rows become proper employee records.
     *
     * These three columns only ever apply when type = 'salary'; is_active
     * already carries Active vs Left, so no status column is needed.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('role')->nullable()->after('payee');
            $table->date('joined_on')->nullable()->after('role');
            $table->string('phone')->nullable()->after('joined_on');
        });

        // "Sanjai Salary" -> "Sanjai". The module is called Salaries, so
        // repeating it in every name is noise. Done in PHP rather than raw
        // SQL because the string functions differ between MySQL and the
        // SQLite the test suite runs on. Only a handful of rows.
        DB::table('expenses')->where('type', 'salary')->orderBy('id')
            ->each(function ($row) {
                if (str_ends_with($row->name, ' Salary')) {
                    DB::table('expenses')->where('id', $row->id)->update([
                        'name' => trim(substr($row->name, 0, -7)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('expenses')->where('type', 'salary')->orderBy('id')
            ->each(function ($row) {
                if (! str_ends_with($row->name, ' Salary')) {
                    DB::table('expenses')->where('id', $row->id)->update([
                        'name' => $row->name.' Salary',
                    ]);
                }
            });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['role', 'joined_on', 'phone']);
        });
    }
};
