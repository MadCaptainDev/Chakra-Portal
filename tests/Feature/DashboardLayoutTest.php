<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DashboardLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which Dashboard widgets a person shows, and in what order -- see
 * App\Support\DashboardLayout and DashboardLayoutController.
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_user_gets_every_widget_visible_in_the_default_order(): void
    {
        $widgets = DashboardLayout::resolveFor(User::factory()->create());

        $this->assertSame(DashboardLayout::DEFAULT_ORDER, $widgets->pluck('key')->all());
        $this->assertTrue($widgets->every(fn (array $w) => $w['visible']));
    }

    public function test_a_saved_order_and_hidden_set_are_honoured(): void
    {
        $user = User::factory()->create([
            'dashboard_widgets_order' => ['delivery', 'portfolio', 'reel_today', 'month_glance'],
            'dashboard_widgets_disabled' => ['portfolio'],
        ]);

        $widgets = DashboardLayout::resolveFor($user);

        $this->assertSame('delivery', $widgets->first()['key']);
        $this->assertFalse($widgets->firstWhere('key', 'portfolio')['visible']);
        $this->assertTrue($widgets->firstWhere('key', 'delivery')['visible']);
    }

    /**
     * A saved order from before a widget existed should not lose that
     * widget forever -- it gets appended rather than dropped.
     */
    public function test_a_widget_missing_from_a_stale_saved_order_is_appended(): void
    {
        $user = User::factory()->create([
            'dashboard_widgets_order' => ['delivery', 'portfolio'],
            'dashboard_widgets_disabled' => null,
        ]);

        $keys = DashboardLayout::resolveFor($user)->pluck('key');

        $this->assertSame(['delivery', 'portfolio'], $keys->take(2)->all());
        $this->assertCount(count(DashboardLayout::WIDGETS), $keys);
        $this->assertTrue($keys->contains('month_glance'));
    }

    /**
     * A saved order naming a widget that no longer exists should not break
     * the render -- it is silently dropped rather than left in the list.
     */
    public function test_an_unknown_key_in_a_saved_order_is_dropped(): void
    {
        $user = User::factory()->create([
            'dashboard_widgets_order' => ['delivery', 'a_widget_that_was_removed', 'portfolio'],
        ]);

        $keys = DashboardLayout::resolveFor($user)->pluck('key');

        $this->assertFalse($keys->contains('a_widget_that_was_removed'));
        $this->assertCount(count(DashboardLayout::WIDGETS), $keys);
    }

    public function test_saving_a_layout_hides_the_widget_on_the_next_render(): void
    {
        $user = User::factory()->create();

        $widgets = collect(DashboardLayout::DEFAULT_ORDER)->map(fn (string $key) => [
            'key' => $key,
            'visible' => $key !== 'delivery',
        ])->values()->all();

        $this->actingAs($user)
            ->put(route('dashboard.layout.update'), ['widgets' => json_encode($widgets)])
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame(['delivery'], $user->dashboard_widgets_disabled);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Behind on reels this month')
            ->assertSee('Shoots this week');
    }

    public function test_an_unknown_widget_key_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('dashboard.layout.update'), [
                'widgets' => json_encode([['key' => 'not_a_real_widget', 'visible' => true]]),
            ])
            ->assertSessionHasErrors();
    }

    public function test_resetting_clears_both_saved_preferences(): void
    {
        $user = User::factory()->create([
            'dashboard_widgets_order' => ['delivery', 'portfolio'],
            'dashboard_widgets_disabled' => ['portfolio'],
        ]);

        $this->actingAs($user)
            ->delete(route('dashboard.layout.reset'))
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNull($user->dashboard_widgets_order);
        $this->assertNull($user->dashboard_widgets_disabled);
    }

    public function test_a_guest_cannot_save_a_layout(): void
    {
        $this->put(route('dashboard.layout.update'), ['widgets' => json_encode([['key' => 'money', 'visible' => true]])])
            ->assertRedirect(route('login'));
    }
}
