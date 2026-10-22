<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PreOrder;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GuestDishSelectionService;
use App\Services\GuestInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestDishSelectionReadTest extends TestCase
{
    use RefreshDatabase;

    private Reservation $reservation;

    private ReservationGuest $guest;

    private MenuCategory $category;

    private MenuItem $bowl;

    private MenuItem $salad;

    private string $token;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Green Tasty Test', 'slug' => 'test', 'address' => 'Test Address']);
        $this->reservation = $restaurant->reservations()->create([
            'owner_user_id' => $owner->id,
            'code' => 'GT-SELECTION-READ',
            'reserved_at' => now()->addDay(),
            'guest_count' => 2,
        ]);
        $this->category = $restaurant->menuCategories()->create(['name' => 'Main Courses', 'slug' => 'main']);
        $this->bowl = $this->category->items()->create(['name' => 'Vegetable Bowl', 'price' => '12.50']);
        $this->salad = $this->category->items()->create(['name' => 'Garden Salad', 'price' => '8.50']);
        $invitation = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Alice']);
        $this->guest = ReservationGuest::findOrFail($invitation['guest_id']);
        $this->token = basename(parse_url($invitation['invitation_url'], PHP_URL_PATH));
        $this->url = route('invitations.selections.show', ['token' => $this->token]);
    }

    public function test_empty_selections_are_returned_without_creating_an_order_or_requiring_login(): void
    {
        $guestBefore = $this->guest->getRawOriginal();

        $response = $this->get($this->url)->assertOk()->assertExactJson([
            'items' => [], 'total_quantity' => 0, 'subtotal' => '0.00',
        ]);

        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['request']->hasSession());
        $this->assertSame($guestBefore, $this->guest->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('pre_order_items', 0);
        $this->assertGuest();
    }

    public function test_an_existing_order_without_this_guests_items_returns_zero_totals(): void
    {
        $preOrder = $this->reservation->preOrder()->create(['status' => 'draft']);
        $otherGuest = $this->reservation->guests()->create(['name' => 'Bob Private']);
        $preOrder->items()->create([
            'reservation_guest_id' => $otherGuest->id,
            'menu_item_id' => $this->bowl->id,
            'quantity' => 5,
            'unit_price' => '12.50',
        ]);

        $this->getJson($this->url)->assertOk()->assertExactJson([
            'items' => [], 'total_quantity' => 0, 'subtotal' => '0.00',
        ]);
        $this->assertDatabaseCount('pre_order_items', 1);
    }

    public function test_saved_selections_match_the_write_summary_and_expose_only_the_public_fields(): void
    {
        $written = $this->putJson(route('invitations.selections.update', ['token' => $this->token]), ['items' => [
            ['menu_item_id' => $this->salad->id, 'quantity' => 1],
            ['menu_item_id' => $this->bowl->id, 'quantity' => 2],
        ]])->assertOk()->json();

        $response = $this->getJson($this->url)->assertOk()->assertExactJson([
            'items' => [
                ['menu_item_id' => $this->bowl->id, 'name' => 'Vegetable Bowl', 'quantity' => 2, 'unit_price' => '12.50', 'subtotal' => '25.00'],
                ['menu_item_id' => $this->salad->id, 'name' => 'Garden Salad', 'quantity' => 1, 'unit_price' => '8.50', 'subtotal' => '8.50'],
            ],
            'total_quantity' => 3,
            'subtotal' => '33.50',
        ]);

        $this->assertSame($written, $response->json());
        $response->assertDontSee($this->token)->assertDontSee($this->guest->invitation_token);
        $response->assertJsonMissingPath('guest_id')->assertJsonMissingPath('reservation_id');
    }

    public function test_historical_prices_remain_readable_after_submission_and_menu_availability_changes(): void
    {
        $preOrder = $this->savedOrder();
        $preOrder->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->bowl->update(['price' => '99.99', 'is_available' => false]);
        $this->category->update(['is_active' => false]);
        $orderBefore = $preOrder->fresh()->getRawOriginal();
        $itemsBefore = $preOrder->items()->get()->toArray();
        $guestBefore = $this->guest->getRawOriginal();
        $this->travel(2)->seconds();

        foreach (range(1, 2) as $attempt) {
            $this->getJson($this->url)->assertOk()->assertExactJson([
                'items' => [[
                    'menu_item_id' => $this->bowl->id, 'name' => 'Vegetable Bowl',
                    'quantity' => 2, 'unit_price' => '12.50', 'subtotal' => '25.00',
                ]],
                'total_quantity' => 2,
                'subtotal' => '25.00',
            ]);
        }

        $this->assertSame($orderBefore, $preOrder->fresh()->getRawOriginal());
        $this->assertSame($itemsBefore, $preOrder->items()->get()->toArray());
        $this->assertSame($guestBefore, $this->guest->fresh()->getRawOriginal());
    }

    public function test_decimal_totals_are_exact_and_keep_two_fractional_digits(): void
    {
        $preOrder = $this->savedOrder('0.10', 3);
        $preOrder->items()->create([
            'reservation_guest_id' => $this->guest->id,
            'menu_item_id' => $this->salad->id,
            'quantity' => 2,
            'unit_price' => '0.20',
        ]);

        $this->getJson($this->url)->assertOk()
            ->assertJsonPath('items.0.unit_price', '0.10')->assertJsonPath('items.0.subtotal', '0.30')
            ->assertJsonPath('items.1.unit_price', '0.20')->assertJsonPath('items.1.subtotal', '0.40')
            ->assertJsonPath('total_quantity', 5)->assertJsonPath('subtotal', '0.70');
    }

    public function test_read_scope_excludes_other_guests_and_other_reservations_even_with_forged_query_ids(): void
    {
        $preOrder = $this->savedOrder();
        $otherGuest = $this->reservation->guests()->create(['name' => 'Bob Private', 'email' => 'bob.private@example.com']);
        $preOrder->items()->create([
            'reservation_guest_id' => $otherGuest->id,
            'menu_item_id' => $this->salad->id,
            'quantity' => 50,
            'unit_price' => '9.00',
        ]);
        $otherReservation = $this->reservation->restaurant->reservations()->create([
            'owner_user_id' => $this->reservation->owner_user_id,
            'code' => 'GT-OTHER-READ',
            'reserved_at' => now()->addDays(2),
            'guest_count' => 1,
        ]);
        $otherOrder = $otherReservation->preOrder()->create(['status' => 'draft']);
        // An inconsistent legacy link must not pull another reservation's rows into this view.
        $otherOrder->items()->create([
            'reservation_guest_id' => $this->guest->id,
            'menu_item_id' => $this->salad->id,
            'quantity' => 99,
            'unit_price' => '10.00',
        ]);

        $this->getJson($this->url.'?guest_id='.$otherGuest->id.'&reservation_id='.$otherReservation->id.'&pre_order_id='.$otherOrder->id)
            ->assertOk()->assertExactJson([
                'items' => [[
                    'menu_item_id' => $this->bowl->id, 'name' => 'Vegetable Bowl',
                    'quantity' => 2, 'unit_price' => '12.50', 'subtotal' => '25.00',
                ]],
                'total_quantity' => 2,
                'subtotal' => '25.00',
            ])->assertDontSee('Bob Private')->assertDontSee('bob.private@example.com');
    }

    #[DataProvider('invalidInvitationStates')]
    public function test_invalid_invitation_reads_return_404_before_the_selection_service_is_called(string $state): void
    {
        $this->savedOrder();
        $token = $this->token;
        if ($state === 'malformed') {
            $token = 'invalid';
        } elseif ($state === 'unknown') {
            $token = str_repeat('a', 64);
        } elseif ($state === 'hash') {
            $token = $this->guest->invitation_token;
        } elseif ($state === 'expired') {
            $this->travel(8)->days();
        } elseif ($state === 'revoked') {
            app(GuestInvitationService::class)->revoke($this->reservation, $this->guest);
        } else {
            app(GuestInvitationService::class)->regenerate($this->reservation, $this->guest);
        }
        $this->mock(GuestDishSelectionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('view');
        });

        $this->getJson(route('invitations.selections.show', ['token' => $token]))->assertNotFound()
            ->assertExactJson(['message' => 'Invitation not found.'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public static function invalidInvitationStates(): array
    {
        return [['malformed'], ['unknown'], ['hash'], ['expired'], ['revoked'], ['rotated']];
    }

    private function savedOrder(string $price = '12.50', int $quantity = 2): PreOrder
    {
        $preOrder = $this->reservation->preOrder()->create(['status' => 'draft']);
        $preOrder->items()->create([
            'reservation_guest_id' => $this->guest->id,
            'menu_item_id' => $this->bowl->id,
            'quantity' => $quantity,
            'unit_price' => $price,
        ]);

        return $preOrder;
    }
}
