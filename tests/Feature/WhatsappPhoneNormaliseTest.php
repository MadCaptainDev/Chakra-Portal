<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\WhatsappFlow;
use App\Models\WhatsappSetting;
use App\Services\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappPhoneNormaliseTest extends TestCase
{
    use RefreshDatabase;

    public function test_leading_zero_indian_numbers_normalise_to_the_same_wa_id(): void
    {
        $this->assertSame('919876543210', WhatsappSender::normalise('09876543210'));
        $this->assertSame('919876543210', WhatsappSender::normalise('9876543210'));
        $this->assertSame('919876543210', WhatsappSender::normalise('919876543210'));
    }

    public function test_portal_client_lookup_matches_leading_zero_phone(): void
    {
        Client::create([
            'name' => 'SVA Silks',
            'phone' => '09876543210',
            'whatsapp_portal_enabled' => true,
        ]);

        $this->assertNotNull(Client::findForWhatsappPortal('919876543210'));
    }
}
