<?php

namespace Database\Factories;

use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoiceItem>
 */
class RecurringInvoiceItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recurring_invoice_id' => RecurringInvoice::factory(),
            'description' => fake()->words(3, true),
            'quantity' => 1,
            'unit_price' => fake()->randomFloat(2, 100, 10000),
            'sort_order' => 0,
        ];
    }
}
