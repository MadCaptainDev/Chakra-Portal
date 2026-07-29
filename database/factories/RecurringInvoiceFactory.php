<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'label' => fake()->words(2, true),
            'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
            'day_of_month' => 1,
            'due_days' => 7,
            'next_run_on' => now()->format('Y-m-d'),
            'last_generated_on' => null,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
