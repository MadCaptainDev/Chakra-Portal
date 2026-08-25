<?php

namespace Database\Factories;

use App\Models\Routine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Routine>
 */
class RoutineFactory extends Factory
{
    protected $model = Routine::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'description' => null,
            'schedule_type' => Routine::SCHEDULE_DAILY,
            'schedule_interval' => null,
            'day_of_month' => null,
            'completion_mode' => Routine::MODE_SHARED,
            'subject_type' => null,
            'is_active' => true,
            'catch_up_days' => 31,
            'starts_on' => today()->toDateString(),
            'created_by' => User::factory(),
        ];
    }

    public function individual(): static
    {
        return $this->state(fn () => [
            'completion_mode' => Routine::MODE_INDIVIDUAL,
        ]);
    }

    public function everyNDays(int $n): static
    {
        return $this->state(fn () => [
            'schedule_type' => Routine::SCHEDULE_EVERY_N_DAYS,
            'schedule_interval' => $n,
        ]);
    }

    public function monthly(int $dayOfMonth = 31): static
    {
        return $this->state(fn () => [
            'schedule_type' => Routine::SCHEDULE_MONTHLY,
            'day_of_month' => $dayOfMonth,
        ]);
    }

    public function weekdays(): static
    {
        return $this->state(fn () => [
            'schedule_type' => Routine::SCHEDULE_WEEKDAYS,
        ]);
    }
}
