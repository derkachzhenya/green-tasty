<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\PreOrder;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ConsolidatedPreOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create([
            'name' => 'Green Tasty Test',
            'slug' => 'green-tasty-test',
            'address' => 'Test Address',
        ]);
        $this->reservation = $restaurant->reservations()->create([
            'owner_user_id' => $this->owner->id,
            'code' => 'GT-SUMMARY-001',
            'reserved_at' => now()->addDay(),
            'guest_count' => 2,
        ]);
    }

    public function test_owner_can_view_an_empty_summary_without_creating_a_pre_order(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('reservations.pre-order.summary', $this->reservation))
            ->assertOk()
            ->assertExactJson([
                'reservation_id' => $this->reservation->id,
                'pre_order_id' => null,
                'items' => [],
                'guests' => [],
                'total_quantity' => 0,
                'grand_total' => '0.00',
            ]);

        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('pre_order_items', 0);
    }

    public function test_owner_can_view_an_existing_pre_order_without_items(): void
    {
        $preOrder = $this->reservation->preOrder()->create(['status' => 'draft']);

        $this->actingAs($this->owner)
            ->getJson(route('reservations.pre-order.summary', $this->reservation))
            ->assertOk()
            ->assertExactJson([
                'reservation_id' => $this->reservation->id,
                'pre_order_id' => $preOrder->id,
                'items' => [],
                'guests' => [],
                'total_quantity' => 0,
                'grand_total' => '0.00',
            ]);
    }

    public function test_owner_receives_a_populated_json_summary_with_historical_prices(): void
    {
        ['preOrder' => $preOrder, 'dish' => $dish, 'ownerParticipant' => $ownerParticipant, 'guest' => $guest] = $this->populatePreOrder();

        $this->actingAs($this->owner)
            ->getJson(route('reservations.pre-order.summary', $this->reservation))
            ->assertOk()
            ->assertExactJson([
                'reservation_id' => $this->reservation->id,
                'pre_order_id' => $preOrder->id,
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'name' => 'Vegetable Bowl',
                    'quantity' => 3,
                    'subtotal' => '34.50',
                ]],
                'guests' => [
                    [
                        'reservation_guest_id' => $ownerParticipant->id,
                        'name' => $this->owner->name,
                        'items' => [[
                            'menu_item_id' => $dish->id,
                            'name' => 'Vegetable Bowl',
                            'quantity' => 1,
                            'unit_price' => '12.50',
                            'subtotal' => '12.50',
                        ]],
                        'total_quantity' => 1,
                        'subtotal' => '12.50',
                    ],
                    [
                        'reservation_guest_id' => $guest->id,
                        'name' => 'Alice Private',
                        'items' => [[
                            'menu_item_id' => $dish->id,
                            'name' => 'Vegetable Bowl',
                            'quantity' => 2,
                            'unit_price' => '11.00',
                            'subtotal' => '22.00',
                        ]],
                        'total_quantity' => 2,
                        'subtotal' => '22.00',
                    ],
                ],
                'total_quantity' => 3,
                'grand_total' => '34.50',
            ]);

        $this->assertDatabaseCount('pre_orders', 1);
        $this->assertDatabaseCount('pre_order_items', 2);
        $this->assertSame('draft', $preOrder->fresh()->status);
    }

    public function test_a_different_authenticated_user_receives_403_before_the_summary_is_read(): void
    {
        $this->populatePreOrder();
        $this->expectSummaryNotToBeRead();

        $this->actingAs(User::factory()->create())
            ->getJson(route('reservations.pre-order.summary', $this->reservation))
            ->assertForbidden()
            ->assertJsonMissingPath('reservation_id')
            ->assertJsonMissingPath('guests')
            ->assertJsonMissingPath('items')
            ->assertDontSee('Alice Private');
    }

    public function test_an_authenticated_participant_who_is_not_the_owner_also_receives_403(): void
    {
        ['guest' => $guest] = $this->populatePreOrder();
        $participant = User::factory()->create();
        $guest->update(['user_id' => $participant->id]);
        $this->expectSummaryNotToBeRead();

        $this->actingAs($participant)
            ->getJson(route('reservations.pre-order.summary', $this->reservation))
            ->assertForbidden()
            ->assertJsonMissingPath('guests');
    }

    public function test_unauthenticated_json_requests_receive_401_before_the_summary_is_read(): void
    {
        $this->populatePreOrder();
        $this->expectSummaryNotToBeRead();

        $this->getJson(route('reservations.pre-order.summary', $this->reservation))
            ->assertUnauthorized()
            ->assertJsonMissingPath('reservation_id')
            ->assertJsonMissingPath('guests');
    }

    public function test_unauthenticated_browser_requests_are_redirected_to_login(): void
    {
        $this->expectSummaryNotToBeRead();

        $this->get(route('reservations.pre-order.summary', $this->reservation))
            ->assertRedirect(route('login'));
    }

    public function test_an_authenticated_request_for_a_nonexistent_reservation_receives_404(): void
    {
        $this->expectSummaryNotToBeRead();

        $this->actingAs($this->owner)
            ->getJson(route('reservations.pre-order.summary', $this->reservation->id + 1))
            ->assertNotFound()
            ->assertJsonMissingPath('guests');
    }

    public function test_authentication_happens_before_binding_a_nonexistent_reservation(): void
    {
        $this->expectSummaryNotToBeRead();

        $this->getJson(route('reservations.pre-order.summary', $this->reservation->id + 1))
            ->assertUnauthorized();
    }

    /** @return array{preOrder: PreOrder, dish: MenuItem, ownerParticipant: ReservationGuest, guest: ReservationGuest} */
    private function populatePreOrder(): array
    {
        $category = $this->reservation->restaurant->menuCategories()->create([
            'name' => 'Main Courses',
            'slug' => 'main-courses',
        ]);
        $dish = $category->items()->create([
            'name' => 'Vegetable Bowl',
            'price' => '99.99',
        ]);
        $ownerParticipant = $this->reservation->guests()->create([
            'user_id' => $this->owner->id,
            'name' => $this->owner->name,
        ]);
        $guest = $this->reservation->guests()->create([
            'name' => 'Alice Private',
            'email' => 'alice.private@example.com',
        ]);
        $preOrder = $this->reservation->preOrder()->create(['status' => 'draft']);
        $preOrder->items()->create([
            'reservation_guest_id' => $ownerParticipant->id,
            'menu_item_id' => $dish->id,
            'quantity' => 1,
            'unit_price' => '12.50',
        ]);
        $preOrder->items()->create([
            'reservation_guest_id' => $guest->id,
            'menu_item_id' => $dish->id,
            'quantity' => 2,
            'unit_price' => '11.00',
        ]);

        return compact('preOrder', 'dish', 'ownerParticipant', 'guest');
    }

    private function expectSummaryNotToBeRead(): void
    {
        $this->mock(ConsolidatedPreOrderService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('summarize');
        });
    }
}
