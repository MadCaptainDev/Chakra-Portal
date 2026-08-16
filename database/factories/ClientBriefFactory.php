<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientBrief;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientBrief>
 */
class ClientBriefFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'status' => ClientBrief::STATUS_IN_PROGRESS,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => ClientBrief::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
    }
}
