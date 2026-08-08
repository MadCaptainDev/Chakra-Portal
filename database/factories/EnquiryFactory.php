<?php

namespace Database\Factories;

use App\Models\Enquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enquiry>
 */
class EnquiryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('##########'),
            'project' => fake()->randomElement(['Short-form video', 'YouTube long-form', 'Full content retainer']),
            'message' => fake()->paragraph(),
            'ip_address' => fake()->ipv4(),
            'read_at' => null,
            'handled_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()->subHour()]);
    }

    public function handled(): static
    {
        return $this->state(fn () => [
            'read_at' => now()->subHour(),
            'handled_at' => now()->subMinutes(30),
        ]);
    }
}
