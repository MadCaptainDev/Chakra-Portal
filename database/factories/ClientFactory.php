<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'address' => fake()->address(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            // Explicit rather than left to the column's own DB default: a
            // factory-built model returned by create() never re-fetches a
            // DB-only default, so $client->is_active would read null (not
            // even false) on the very instance the test just got back.
            // ClientRequest::prepareForValidation() gives the real create
            // flow this same explicit true, so this only matches it.
            'is_active' => true,
        ];
    }
}
