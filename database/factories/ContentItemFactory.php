<?php

namespace Database\Factories;

use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => ContentItem::SOURCE_YOUTUBE,
            'notion_page_id' => fake()->unique()->uuid(),
            'notion_url' => fake()->url(),
            'title' => fake()->sentence(3),
            'venture' => fake()->company(),
            'status' => 'Video Ready',
            'published_date' => fake()->date(),
            'editor' => fake()->firstName(),
            'notion_created_at' => now(),
            'synced_at' => now(),
        ];
    }
}
