<?php

namespace Tests\Unit;

use App\Models\Routine;
use App\Services\RoutineScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoutineSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private RoutineScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = new RoutineScheduler;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_returns_each_day_in_range(): void
    {
        Carbon::setTestNow('2026-08-10 12:00:00');

        $routine = Routine::factory()->create([
            'schedule_type' => Routine::SCHEDULE_DAILY,
            'starts_on' => '2026-08-08',
        ]);

        $dates = $this->scheduler->datesBetween(
            $routine,
            Carbon::parse('2026-08-08'),
            Carbon::parse('2026-08-10'),
        )->map->toDateString()->all();

        $this->assertSame(['2026-08-08', '2026-08-09', '2026-08-10'], $dates);
    }

    public function test_every_two_days_anchored_on_starts_on(): void
    {
        $routine = Routine::factory()->everyNDays(2)->create([
            'starts_on' => '2026-08-01',
        ]);

        $dates = $this->scheduler->datesBetween(
            $routine,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-07'),
        )->map->toDateString()->all();

        $this->assertSame([
            '2026-08-01',
            '2026-08-03',
            '2026-08-05',
            '2026-08-07',
        ], $dates);
    }

    public function test_every_ten_days(): void
    {
        $routine = Routine::factory()->everyNDays(10)->create([
            'starts_on' => '2026-08-01',
        ]);

        $dates = $this->scheduler->datesBetween(
            $routine,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
        )->map->toDateString()->all();

        $this->assertSame([
            '2026-08-01',
            '2026-08-11',
            '2026-08-21',
            '2026-08-31',
        ], $dates);
    }

    public function test_monthly_31st_clamps_in_february(): void
    {
        $routine = Routine::factory()->monthly(31)->create([
            'starts_on' => '2026-01-31',
        ]);

        $dates = $this->scheduler->datesBetween(
            $routine,
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-03-31'),
        )->map->toDateString()->all();

        $this->assertSame([
            '2026-01-31',
            '2026-02-28',
            '2026-03-31',
        ], $dates);
    }

    public function test_weekdays_skip_saturday_and_sunday(): void
    {
        // 2026-08-10 is Monday.
        $routine = Routine::factory()->weekdays()->create([
            'starts_on' => '2026-08-10',
        ]);

        $dates = $this->scheduler->datesBetween(
            $routine,
            Carbon::parse('2026-08-10'),
            Carbon::parse('2026-08-16'),
        )->map->toDateString()->all();

        $this->assertSame([
            '2026-08-10',
            '2026-08-11',
            '2026-08-12',
            '2026-08-13',
            '2026-08-14',
        ], $dates);
    }

    public function test_never_before_starts_on(): void
    {
        $routine = Routine::factory()->create([
            'schedule_type' => Routine::SCHEDULE_DAILY,
            'starts_on' => '2026-08-05',
        ]);

        $dates = $this->scheduler->datesBetween(
            $routine,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-06'),
        )->map->toDateString()->all();

        $this->assertSame(['2026-08-05', '2026-08-06'], $dates);
    }
}
