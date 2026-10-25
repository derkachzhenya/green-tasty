<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use App\Services\GuestDishSelectionService;
use App\Services\GuestInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestMenuTest extends TestCase
{
    use RefreshDatabase;

    private Reservation $reservation;

    private ReservationGuest $guest;

    private MenuItem $dish;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $restaurant = Restaurant::create(['name' => 'Green Tasty Test', 'slug' => 'test', 'address' => 'Private address']);
        $this->reservation = $restaurant->reservations()->create([
            'owner_user_id' => User::factory()->create()->id,
            'code' => 'PRIVATE-BOOKING-CODE',
            'reserved_at' => '2026-11-01 08:00:00',
            'guest_count' => 2,
        ]);
        $category = $restaurant->menuCategories()->create(['name' => 'Main Courses', 'slug' => 'main']);
        $this->dish = $category->items()->create(['name' => 'Vegetable Bowl', 'description' => 'Fresh vegetables', 'price' => '12.50']);
        $issued = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Alice', 'email' => 'private@example.com']);
        $this->guest = ReservationGuest::findOrFail($issued['guest_id']);
        $this->token = basename(parse_url($issued['invitation_url'], PHP_URL_PATH));
    }

    public function test_browser_invitation_opens_the_stateless_vue_shell_without_account_or_embedded_secrets(): void
    {
        $this->get(route('invitations.show', $this->token), ['Accept' => 'text/html'])
            ->assertOk()->assertViewIs('guest')->assertViewHas('invalid', false)
            ->assertSee('id="guest-app"', false)
            ->assertDontSee($this->token)->assertDontSee($this->guest->invitation_token)
            ->assertDontSee('private@example.com')->assertDontSee('PRIVATE-BOOKING-CODE')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertFalse($this->app['request']->hasSession());
        $this->assertGuest();
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_original_invitation_json_contract_is_preserved(): void
    {
        $expected = app(GuestInvitationService::class)->view($this->token);
        $this->getJson(route('invitations.show', $this->token))->assertOk()->assertExactJson($expected);
        // Generic API clients using a wildcard Accept header still receive JSON.
        $this->get(route('invitations.show', $this->token), ['Accept' => '*/*'])->assertOk()->assertExactJson($expected);
    }

    public function test_empty_page_data_is_read_only_private_and_uses_the_existing_menu_contract(): void
    {
        $before = $this->guest->getRawOriginal();
        $response = $this->getJson($this->url())->assertOk()->assertJson([
            'invitation' => app(GuestInvitationService::class)->view($this->token),
            'selections' => ['items' => [], 'total_quantity' => 0, 'subtotal' => '0.00'],
            'order' => ['status' => null, 'submitted_at' => null, 'editable' => true],
            'timezone' => 'Asia/Tbilisi',
        ])->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['request']->hasSession());
        $this->assertSame($before, $this->guest->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('pre_order_items', 0);
        $response->assertDontSee($this->token)->assertDontSee($this->guest->invitation_token)
            ->assertDontSee('private@example.com')->assertJsonPath('context.address', 'Private address')->assertDontSee('PRIVATE-BOOKING-CODE');
    }

    public function test_page_restores_historical_prices_with_only_eligible_menu_dishes_and_this_guests_selections(): void
    {
        $order = $this->reservation->preOrder()->create(['status' => 'draft']);
        $order->items()->create(['reservation_guest_id' => $this->guest->id, 'menu_item_id' => $this->dish->id, 'quantity' => 3, 'unit_price' => '0.10']);
        $this->dish->update(['price' => '99.99']);
        $other = $this->reservation->guests()->create(['name' => 'Bob Private']);
        $order->items()->create(['reservation_guest_id' => $other->id, 'menu_item_id' => $this->dish->id, 'quantity' => 50, 'unit_price' => '20.00']);
        $category = $this->dish->category;
        $hidden = $category->items()->create(['name' => 'Unavailable dish', 'price' => '1.00', 'is_available' => false]);
        $inactive = $this->reservation->restaurant->menuCategories()->create(['name' => 'Inactive category', 'slug' => 'inactive', 'is_active' => false]);
        $inactive->items()->create(['name' => 'Inactive category dish', 'price' => '5.00']);
        $foreign = Restaurant::create(['name' => 'Other restaurant', 'slug' => 'other', 'address' => 'Other address']);
        $foreign->menuCategories()->create(['name' => 'Foreign category', 'slug' => 'foreign'])->items()->create(['name' => 'Foreign dish', 'price' => '2.00']);
        $otherReservation = $foreign->reservations()->create(['owner_user_id' => $this->reservation->owner_user_id, 'code' => 'OTHER', 'reserved_at' => now()->addDay(), 'guest_count' => 1]);
        // Even an inconsistent legacy guest link must not leak another reservation's rows.
        $otherReservation->preOrder()->create(['status' => 'submitted'])->items()->create(['reservation_guest_id' => $this->guest->id, 'menu_item_id' => $hidden->id, 'quantity' => 99, 'unit_price' => '1.00']);

        $this->getJson($this->url().'?guest_id='.$other->id.'&reservation_id='.$otherReservation->id)->assertOk()
            ->assertJsonPath('selections', app(GuestDishSelectionService::class)->view($this->token))
            ->assertJsonPath('selections.subtotal', '0.30')->assertJsonCount(1, 'selections.items')
            ->assertJsonPath('invitation.menu_categories.0.dishes.0.price', '99.99')
            ->assertJsonCount(1, 'invitation.menu_categories')->assertJsonCount(1, 'invitation.menu_categories.0.dishes')
            ->assertJsonPath('order.editable', true)->assertDontSee('Bob Private')
            ->assertDontSee('Foreign dish')->assertJsonPath('catalog.0.dishes.1.available', false)->assertDontSee('Inactive category');

        $this->putJson(route('invitations.selections.update', $this->token), ['items' => [['menu_item_id' => $this->dish->id, 'quantity' => 4]]], ['If-Match' => '"0"'])->assertOk()->assertJsonPath('subtotal', '0.40');
        $summary = app(ConsolidatedPreOrderService::class)->summarize($this->reservation);
        $this->assertSame('1000.40', $summary['grand_total']);
    }

    #[DataProvider('lockedStates')]
    public function test_noneditable_status_and_timestamp_are_reported_without_modifying_the_order(string $status, bool $submitted): void
    {
        $order = $this->reservation->preOrder()->create(['status' => $status, 'submitted_at' => $submitted ? now() : null]);
        $order->items()->create(['reservation_guest_id' => $this->guest->id, 'menu_item_id' => $this->dish->id, 'quantity' => 2, 'unit_price' => '12.50']);
        $this->dish->update(['is_available' => false]);
        $before = $order->fresh()->getRawOriginal();
        $this->getJson($this->url())->assertOk()->assertJsonPath('order.editable', false)
            ->assertJsonPath('order.status', $status)->assertJsonPath('selections.subtotal', '25.00')
            ->assertJsonCount(0, 'invitation.menu_categories.0.dishes');
        $this->putJson(route('invitations.selections.update', $this->token), ['items' => []], ['If-Match' => '"0"'])->assertConflict();
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pre_order_items', 1);
    }

    public static function lockedStates(): array
    {
        return [['submitted', true], ['submitted', false], ['draft', true], ['cancelled', false]];
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_links_return_404_for_html_and_page_data_before_reading_selections(string $state): void
    {
        $service = app(GuestInvitationService::class);
        $token = $this->token;
        match ($state) {
            'expired' => $this->guest->forceFill(['invitation_expires_at' => now()->subSecond()])->save(),
            'revoked' => $service->revoke($this->reservation, $this->guest),
            'regenerated' => $service->regenerate($this->reservation, $this->guest),
            'hash' => $token = $this->guest->invitation_token,
            'unknown' => $token = str_repeat('a', 64),
            default => $token = 'invalid',
        };
        $this->mock(GuestDishSelectionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('view');
        });
        $this->get(route('invitations.show', $token), ['Accept' => 'text/html'])
            ->assertNotFound()->assertViewHas('invalid', true)->assertDontSee('Alice')->assertDontSee($this->guest->invitation_token);
        $this->getJson(route('invitations.menu', $token))->assertNotFound()
            ->assertExactJson(['message' => 'Invitation not found.'])->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function invalidStates(): array
    {
        return [['expired'], ['revoked'], ['regenerated'], ['hash'], ['unknown'], ['malformed']];
    }

    private function url(): string
    {
        return route('invitations.menu', $this->token);
    }
}
