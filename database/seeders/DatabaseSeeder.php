<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        CompanySetting::current();

        // Studio duty templates (empty subjects / permitted — admin toggles real IDs).
        $this->call(RoutineDutyPlansSeeder::class);
    }
}
