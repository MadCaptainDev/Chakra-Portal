<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quotation_number' => 'QT-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'client_id' => Client::factory(),
            'quotation_date' => now()->format('Y-m-d'),
            'valid_until' => null,
            'intro_text' => fake()->sentence(),
            'subtotal' => 0,
            'total' => 0,
            'status' => Quotation::STATUS_DRAFT,
            'created_by' => User::factory(),
        ];
    }
}
