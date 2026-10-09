<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DailyCheck;
use App\Models\Todo;
use App\Models\User;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Support\DailyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The editing team's daily WhatsApp messages, word for word as they send them.
 */
class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    private const MORNING = <<<'TXT'
09.10.2026

Daily Task:

SANJAI :

1.SVA - Sharara Kurti Set
2.SVA Tier 2 - Boys Collection
3.SVA Tier 2 - Stone Work Shirt
4.SVA Tier 2 - Sharara Kurti Set
5.SA - Vintage house Elements and Uses

GOKUL:

1.SVA Tier 2 - Customer Review
2.Suryas - Cream Bun Montage
3.Janet - 10 Foods For New Moms
4.SVA - Chettinad Cotton Frock
TXT;

    private const EVENING = <<<'TXT'
Daily Task:

SANJAI :

1.SVA - Sharara Kurti Set (Completed)
2.SVA Tier 2 - Boys Collection (Completed) 
3.SVA Tier 2 - Stone Work Shirt
4.SVA Tier 2 - Sharara Kurti Set
5.SA - Vintage house Elements and Uses (Completed)
6.Mahavir - Mannaparai Door Ad (Completed)

GOKUL:

1.SVA Tier 2 - Customer Review (Completed)
2.Suryas - Cream Bun Montage (Completed)
3.Janet - 10 Foods For New Moms
4.SVA - Chettinad Cotton Frock (Edit in progress)
 

IG POST BY

GOKUL & SANJAI 

TODAY FINAL OUT (SANJAI BRO OUT ) PENDING

DONE
TXT;

    private const EOD = <<<'TXT'
EDITING TEAM | EOD CHECK*

1. Videos Posted — ✅ 
2. Notion Updated — ✅ 
3. Final Outputs Moved to Main Disk — ❌(SANJAI BRO out pending)
4. Hard Disk Management — ✅

5. Group To-Do Checked —✅ Today  
6. TIME SHEET - ✅
7. All VENTURE GROUP  IG & LINK -✅ Surya's pending (ig)


**Status: Pending
TXT;

    private User $sanjai;

    private User $gokul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 10, 9, 10, 0));
        WhatsappSetting::current()->update(['access_token' => 'EAAG-test-token', 'phone_number_id' => '556677889900']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);

        $this->sanjai = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai', 'phone' => '9000000011']);
        $this->gokul = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Gokul', 'phone' => '9000000012']);
        foreach (['SVA Silks', 'Janet', 'Mahavir Timbers , Doors & Frames'] as $name) {
            Client::factory()->create(['name' => $name, 'notion_venture' => $name]);
        }
        Client::factory()->create(['name' => 'Surya’s Restaurant', 'notion_venture' => 'Surya’s Restaurant']);
    }

    private function send(string $text, string $from = '919000000011'): void
    {
        WhatsappWebhookEvent::ingest([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919876543210', 'phone_number_id' => '556677889900'],
                'messages' => [['from' => $from, 'id' => 'wamid.'.uniqid(), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
            ]]]]],
        ]);
    }

    private function lastReply(): string
    {
        return (string) data_get(collect(Http::recorded())->last()[0]->data(), 'text.body');
    }

    public function test_the_morning_list_is_read_as_people_clients_and_tasks(): void
    {
        $r = DailyReport::parse(self::MORNING);

        $this->assertSame('2026-10-09', $r['date']->toDateString());
        $this->assertSame(['SANJAI', 'GOKUL'], array_keys($r['people']));
        $this->assertCount(5, $r['people']['SANJAI']);
        $this->assertSame(['client' => 'SVA Tier 2', 'title' => 'SVA Tier 2 - Boys Collection', 'line' => 'SVA Tier 2 - Boys Collection', 'status' => null], $r['people']['SANJAI'][1]);
    }

    public function test_the_evening_update_reads_marks_and_ignores_the_footer(): void
    {
        $r = DailyReport::parse(self::EVENING);

        $this->assertCount(6, $r['people']['SANJAI']);
        $this->assertCount(4, $r['people']['GOKUL'], 'IG POST BY / GOKUL & SANJAI are not another person');
        $this->assertSame([DailyReport::DONE, DailyReport::DONE, null, null, DailyReport::DONE, DailyReport::DONE], array_column($r['people']['SANJAI'], 'status'));
        $this->assertSame(DailyReport::IN_PROGRESS, $r['people']['GOKUL'][3]['status']);
        $this->assertSame('SVA - Chettinad Cotton Frock', $r['people']['GOKUL'][3]['title']);
    }

    public function test_the_eod_check_is_read_with_ticks_crosses_and_notes(): void
    {
        $c = DailyReport::parse(self::EOD)['check'];

        $this->assertSame('EDITING TEAM', $c['team']);
        $this->assertCount(7, $c['items']);
        $this->assertSame(['label' => 'Final Outputs Moved to Main Disk', 'ok' => false, 'note' => 'SANJAI BRO out pending'], $c['items'][2]);
        $this->assertSame('Pending', $c['status']);
    }

    public function test_a_day_on_whatsapp_end_to_end(): void
    {
        // Morning: nine to-dos, against the right people and clients.
        $this->send(self::MORNING);

        $this->assertSame(5, Todo::where('user_id', $this->sanjai->id)->count());
        $this->assertSame(4, Todo::where('user_id', $this->gokul->id)->count());
        $this->assertSame('SVA Silks', Todo::where('title', 'SVA Tier 2 - Boys Collection')->value('venture'));
        $this->assertSame('Surya’s Restaurant', Todo::where('title', 'Suryas - Cream Bun Montage')->value('venture'));
        $this->assertStringContainsString('Sanjai: 5 tasks (5 new)', $this->lastReply());

        // Evening: the same to-dos move on; Mahavir's door ad is new and done.
        $this->send(self::EVENING);

        $this->assertSame(6, Todo::where('user_id', $this->sanjai->id)->count());
        $this->assertSame(4, Todo::where('user_id', $this->sanjai->id)->where('status', Todo::STATUS_COMPLETED)->count());
        $this->assertSame('Mahavir Timbers , Doors & Frames', Todo::where('title', 'Mahavir - Mannaparai Door Ad')->value('venture'));
        $this->assertSame(Todo::STATUS_STARTED, Todo::where('title', 'SVA - Chettinad Cotton Frock')->value('status'));
        $this->assertSame(2, Todo::where('user_id', $this->gokul->id)->where('status', Todo::STATUS_COMPLETED)->count());
        $this->assertStringContainsString('Sanjai: 6 tasks (1 new) — 4 done, 2 open', $this->lastReply());

        // EOD check: kept for the day, the miss reported back.
        $this->send(self::EOD);

        $check = DailyCheck::sole();
        $this->assertSame('Pending', $check->status);
        $this->assertCount(1, $check->missed());
        $this->assertStringContainsString('6 of 7 done', $this->lastReply());
    }

    public function test_sending_the_morning_list_twice_makes_no_duplicates(): void
    {
        $this->send(self::MORNING);
        $this->send(self::MORNING);

        $this->assertSame(9, Todo::count());
    }

    public function test_a_number_not_on_staff_is_not_treated_as_a_report(): void
    {
        $this->send(self::MORNING, '919999999999');

        $this->assertSame(0, Todo::count());
    }
}
