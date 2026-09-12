<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\EmployeeRecognition;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\RecognitionAwarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecognitionAwarderTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    /**
     * was_backdated is deliberately not fillable -- it is stamped at creation
     * rather than posted -- so the flag has to be forced on here the same way
     * the controller stamps it.
     */
    private function entry(User $user, Carbon $day, bool $backdated = false): TimesheetEntry
    {
        $entry = TimesheetEntry::create([
            'user_id' => $user->id,
            'worked_on' => $day->toDateString(),
            'task' => 'Editing',
            'minutes' => 120,
        ]);

        $entry->forceFill(['was_backdated' => $backdated])->save();

        return $entry;
    }

    private function piece(?User $owner, string $status, Carbon $date): ContentItem
    {
        return ContentItem::factory()->create([
            'title' => 'Bridal reel',
            'status' => $status,
            'published_date' => $date->toDateString(),
            'assigned_user_id' => $owner?->id,
        ]);
    }

    /** Named around PHPUnit's own final run(), which this may not shadow. */
    private function runAwarder(): int
    {
        return app(RecognitionAwarder::class)->run();
    }

    // ——— Timesheets ———

    public function test_a_day_logged_on_the_day_earns_recognition(): void
    {
        $user = $this->staff();
        $this->entry($user, today()->subDay());

        $this->runAwarder();

        $this->assertSame(1, EmployeeRecognition::where('user_id', $user->id)
            ->where('kind', EmployeeRecognition::KIND_TIMESHEET_ON_TIME)->count());
    }

    public function test_a_backdated_day_earns_nothing(): void
    {
        $user = $this->staff();
        $this->entry($user, today()->subDay(), backdated: true);

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::where('user_id', $user->id)->count());
    }

    /**
     * One reconstructed entry is enough to say the day was not filed on the
     * day -- otherwise a single on-time entry would launder the rest.
     */
    public function test_one_backdated_entry_disqualifies_the_whole_day(): void
    {
        $user = $this->staff();
        $day = today()->subDay();
        $this->entry($user, $day);
        $this->entry($user, $day, backdated: true);

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::where('user_id', $user->id)->count());
    }

    public function test_cancelled_work_is_not_counted_as_a_logged_day(): void
    {
        $user = $this->staff();
        $entry = $this->entry($user, today()->subDay());
        $entry->forceFill(['status' => TimesheetEntry::STATUS_CANCELLED])->save();

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::where('user_id', $user->id)->count());
    }

    public function test_today_is_not_judged_yet(): void
    {
        $user = $this->staff();
        $this->entry($user, today());

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::where('user_id', $user->id)->count());
    }

    // ——— Content ———

    public function test_a_piece_that_landed_on_its_date_earns_recognition(): void
    {
        $user = $this->staff();
        $this->piece($user, 'Published', today()->subDay());

        $this->runAwarder();

        $this->assertSame(1, EmployeeRecognition::where('user_id', $user->id)
            ->where('kind', EmployeeRecognition::KIND_CONTENT_ON_TIME)->count());
    }

    public function test_a_piece_still_in_progress_on_its_date_earns_nothing(): void
    {
        $user = $this->staff();
        $this->piece($user, 'Edit in Progress', today()->subDay());

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::where('user_id', $user->id)->count());
    }

    /**
     * Nothing here records a miss. A piece that slipped simply earns nothing
     * -- there is no row anywhere saying somebody was late.
     */
    public function test_a_missed_piece_leaves_no_record_at_all(): void
    {
        $user = $this->staff();
        $this->piece($user, 'To Be Edited', today()->subDays(3));

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::count());
    }

    public function test_a_piece_with_no_owner_earns_nobody_anything(): void
    {
        $this->piece(null, 'Published', today()->subDay());

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::count());
    }

    public function test_a_client_login_never_earns_staff_recognition(): void
    {
        $client = Client::factory()->create();
        $login = User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);

        $this->piece($login, 'Published', today()->subDay());

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::count());
    }

    // ——— Running it more than once ———

    /**
     * The whole reason source_key carries a unique index: the catch-up
     * middleware walks the same fortnight every single day.
     */
    public function test_running_it_again_awards_nothing_further(): void
    {
        $user = $this->staff();
        $this->entry($user, today()->subDay());
        $this->piece($user, 'Published', today()->subDay());

        $this->assertSame(2, $this->runAwarder());
        $this->assertSame(0, $this->runAwarder());
        $this->assertSame(2, EmployeeRecognition::count());
    }

    public function test_a_gap_of_several_days_is_caught_up_rather_than_lost(): void
    {
        $user = $this->staff();
        $this->entry($user, today()->subDays(2));
        $this->entry($user, today()->subDays(5));

        $this->runAwarder();

        $this->assertSame(2, EmployeeRecognition::where('user_id', $user->id)->count());
    }

    public function test_nothing_older_than_the_catchup_window_is_awarded(): void
    {
        $user = $this->staff();
        $this->entry($user, today()->subDays(RecognitionAwarder::MAX_CATCHUP_DAYS + 3));

        $this->runAwarder();

        $this->assertSame(0, EmployeeRecognition::count());
    }
}
