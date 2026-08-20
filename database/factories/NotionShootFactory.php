<?php

namespace Database\Factories;

use App\Models\NotionShoot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotionShoot>
 */
class NotionShootFactory extends Factory
{
    protected $model = NotionShoot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notion_page_id' => fake()->unique()->uuid(),
            'notion_url' => fake()->url(),
            'title' => fake()->sentence(3),
            'status' => 'Planned',
            'client' => fake()->company(),
            'team' => fake()->firstName(),
            'host_model' => fake()->firstName(),
            'location' => fake()->city(),
            'shoot_date' => fake()->date(),
            'duration' => fake()->randomFloat(2, 1, 8),
            'video_count' => (string) fake()->numberBetween(1, 6),
            'notion_created_at' => now(),
            'synced_at' => now(),
        ];
    }
}
