<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ContentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentAccount>
 */
class ContentAccountFactory extends Factory
{
    protected $model = ContentAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => fake()->company(),
            'monthly_target' => null,
        ];
    }
}
