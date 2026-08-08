<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Enquiry;
use App\Notifications\EnquiryReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EnquiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function validEnquiry(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Meera Raj',
            'email' => 'meera@example.test',
            'phone' => '9876543210',
            'project' => 'Short-form video',
            'message' => 'We need a monthly slate of reels for a new product line.',
        ], $overrides);
    }

    public function test_an_enquiry_is_mailed_to_the_studio(): void
    {
        Notification::fake();

        CompanySetting::current()->update(['notification_email' => 'studio@example.test']);

        $response = $this->post(route('enquiry.store'), $this->validEnquiry());

        $response->assertRedirect(route('home').'#contact');
        $response->assertSessionHas('status');

        Notification::assertSentOnDemand(
            EnquiryReceived::class,
            function (EnquiryReceived $notification, array $channels, AnonymousNotifiable $notifiable) {
                return $notifiable->routes['mail'] === 'studio@example.test'
                    && $notification->enquiry['name'] === 'Meera Raj'
                    && $notification->enquiry['message'] === 'We need a monthly slate of reels for a new product line.';
            }
        );
    }

    public function test_enquiry_falls_back_to_the_configured_from_address(): void
    {
        Notification::fake();
        config(['mail.from.address' => 'fallback@example.test']);

        // notification_email is null out of the box.
        $this->post(route('enquiry.store'), $this->validEnquiry());

        Notification::assertSentOnDemand(
            EnquiryReceived::class,
            fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'fallback@example.test'
        );
    }

    public function test_enquiry_requires_a_name_email_and_real_message(): void
    {
        Notification::fake();

        $response = $this->post(route('enquiry.store'), [
            'name' => '',
            'email' => 'not-an-email',
            'message' => 'hi',
        ]);

        $response->assertSessionHasErrors(['name', 'email', 'message']);
        Notification::assertNothingSent();
    }

    public function test_the_honeypot_rejects_bots(): void
    {
        Notification::fake();

        // A human never sees the "website" field, so a filled one is a bot.
        $response = $this->post(route('enquiry.store'), $this->validEnquiry([
            'website' => 'http://spam.example',
        ]));

        $response->assertSessionHasErrors('website');
        Notification::assertNothingSent();
    }

    public function test_enquiries_are_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('enquiry.store'), $this->validEnquiry())->assertRedirect();
        }

        $this->post(route('enquiry.store'), $this->validEnquiry())->assertStatus(429);
    }

    public function test_an_enquiry_is_stored_with_its_details(): void
    {
        Notification::fake();

        $this->post(route('enquiry.store'), $this->validEnquiry());

        $enquiry = Enquiry::sole();

        $this->assertSame('Meera Raj', $enquiry->name);
        $this->assertSame('meera@example.test', $enquiry->email);
        $this->assertSame('9876543210', $enquiry->phone);
        $this->assertSame('Short-form video', $enquiry->project);

        // Arrives unread and unhandled - it is the inbox's job to change that.
        $this->assertTrue($enquiry->isUnread());
        $this->assertFalse($enquiry->isHandled());
        $this->assertSame('unread', $enquiry->displayStatus());
    }

    public function test_the_enquiry_survives_a_dead_mailer(): void
    {
        // Production runs MAIL_MAILER=log with LOG_LEVEL=error, which discards
        // the mail record outright - the row is the only durable copy, so it
        // has to be written whether or not delivery works.
        config(['mail.default' => 'no-such-mailer']);

        $response = $this->post(route('enquiry.store'), $this->validEnquiry());

        $response->assertRedirect(route('home').'#contact');
        $response->assertSessionHasNoErrors();

        // The sender is told we have it, because we do.
        $response->assertSessionHas('status');
        $this->assertSame(1, Enquiry::count());
        $this->assertSame('meera@example.test', Enquiry::sole()->email);
    }

    public function test_rejected_submissions_are_not_stored(): void
    {
        Notification::fake();

        $this->post(route('enquiry.store'), $this->validEnquiry(['website' => 'http://spam.example']));
        $this->post(route('enquiry.store'), ['name' => '', 'email' => 'nope', 'message' => 'hi']);

        $this->assertSame(0, Enquiry::count());
    }
}
