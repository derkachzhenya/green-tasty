<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsolidatedPreOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidatedPreOrderService $service;

    private Restaurant $restaurant;

    private MenuCategory $category;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ConsolidatedPreOrderService::class);
        $this->owner = User::factory()->create();
        $this->restaurant = Restaurant::create([
            'name' => 'Green Tasty Test',
            'slug' => 'green-tasty-test',
            'address' => 'Test Address',
        ]);
        $this->category = $this->restaurant->menuCategories()->create([
            'name' => 'Main Courses',
            'slug' => 'main-courses',
        ]);
    }

    public function test_reservation_without_a_pre_order_returns_an_empty_summary(): void
    {
        $reservation = $this->createReservation();
        $reservation->guests()->create(['name' => 'Alice']);

        $this->assertSame([
            'reservation_id' => $reservation->id,
            'pre_order_id' => null,
            'items' => [],
            'guests' => [],
            'total_quantity' => 0,
            'grand_total' => '0.00',
        ], $this->service->summarize($reservation));
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_pre_order_without_items_returns_an_empty_summary(): void
    {
        $reservation = $this->createReservation();
        $reservation->guests()->create(['name' => 'Alice']);
        $preOrder = $reservation->preOrder()->create(['status' => 'draft']);

        $this->assertSame([
            'reservation_id' => $reservation->id,
            'pre_order_id' => $preOrder->id,
            'items' => [],
            'guests' => [],
            'total_quantity' => 0,
            'grand_total' => '0.00',
        ], $this->service->summarize($reservation));
    }

    public function test_identical_dishes_are_consolidated_across_guests_with_individual_breakdowns(): void
    {
        $reservation = $this->createReservation();
        $owner = $reservation->guests()->create([
            'user_id' => $this->owner->id,
            'name' => $this->owner->name,
        ]);
        $alice = $reservation->guests()->create(['name' => 'Alice']);
        $reservation->guests()->create(['name' => 'No selections']);
        $dish = $this->createDish('Vegetable Bowl');
        $this->addItem($reservation, $owner, $dish, 1, '12.50');
        $this->addItem($reservation, $alice, $dish, 2, '12.50');

        $summary = $this->service->summarize($reservation);

        $this->assertSame([[
            'menu_item_id' => $dish->id,
            'name' => 'Vegetable Bowl',
            'quantity' => 3,
            'subtotal' => '37.50',
        ]], $summary['items']);
        $this->assertSame([
            [
                'reservation_guest_id' => $owner->id,
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
                'reservation_guest_id' => $alice->id,
                'name' => 'Alice',
                'items' => [[
                    'menu_item_id' => $dish->id,
                    'name' => 'Vegetable Bowl',
                    'quantity' => 2,
                    'unit_price' => '12.50',
                    'subtotal' => '25.00',
                ]],
                'total_quantity' => 2,
                'subtotal' => '25.00',
            ],
        ], $summary['guests']);
        $this->assertSame(3, $summary['total_quantity']);
        $this->assertSame('37.50', $summary['grand_total']);
    }

    public function test_different_dishes_and_guest_totals_are_calculated_separately(): void
    {
        $reservation = $this->createReservation();
        $alice = $reservation->guests()->create(['name' => 'Alice']);
        $bob = $reservation->guests()->create(['name' => 'Bob']);
        $salad = $this->createDish('Garden Salad');
        $bowl = $this->createDish('Vegetable Bowl');
        $this->addItem($reservation, $alice, $salad, 2, '8.50');
        $this->addItem($reservation, $alice, $bowl, 1, '14.00');
        $this->addItem($reservation, $bob, $bowl, 3, '14.00');

        $summary = $this->service->summarize($reservation);

        $this->assertSame([
            ['menu_item_id' => $salad->id, 'name' => 'Garden Salad', 'quantity' => 2, 'subtotal' => '17.00'],
            ['menu_item_id' => $bowl->id, 'name' => 'Vegetable Bowl', 'quantity' => 4, 'subtotal' => '56.00'],
        ], $summary['items']);
        $this->assertCount(2, $summary['guests'][0]['items']);
        $this->assertSame([3, 3], array_column($summary['guests'], 'total_quantity'));
        $this->assertSame(['31.00', '42.00'], array_column($summary['guests'], 'subtotal'));
        $this->assertSame(6, $summary['total_quantity']);
        $this->assertSame('73.00', $summary['grand_total']);
    }

    public function test_different_menu_item_ids_with_the_same_name_are_not_merged(): void
    {
        $reservation = $this->createReservation();
        $guest = $reservation->guests()->create(['name' => 'Alice']);
        $firstDish = $this->createDish('Salad');
        $secondDish = $this->createDish('Salad');
        $this->addItem($reservation, $guest, $firstDish, 1, '8.50');
        $this->addItem($reservation, $guest, $secondDish, 2, '10.00');

        $summary = $this->service->summarize($reservation);

        $this->assertSame([$firstDish->id, $secondDish->id], array_column($summary['items'], 'menu_item_id'));
        $this->assertSame([1, 2], array_column($summary['items'], 'quantity'));
        $this->assertSame('28.50', $summary['grand_total']);
    }

    public function test_historical_prices_are_used_even_when_the_menu_price_and_availability_change(): void
    {
        $reservation = $this->createReservation();
        $guest = $reservation->guests()->create(['name' => 'Alice']);
        $dish = $this->createDish('Vegetable Bowl');
        $this->addItem($reservation, $guest, $dish, 3, '12.50');
        $dish->update(['price' => '99.99', 'is_available' => false]);

        $summary = $this->service->summarize($reservation);

        $this->assertSame('37.50', $summary['items'][0]['subtotal']);
        $this->assertSame('12.50', $summary['guests'][0]['items'][0]['unit_price']);
        $this->assertSame('37.50', $summary['grand_total']);
    }

    public function test_the_same_dish_can_have_different_saved_unit_prices_for_different_guests(): void
    {
        $reservation = $this->createReservation();
        $alice = $reservation->guests()->create(['name' => 'Alice']);
        $bob = $reservation->guests()->create(['name' => 'Bob']);
        $dish = $this->createDish('Garden Salad');
        $this->addItem($reservation, $alice, $dish, 2, '8.50');
        $this->addItem($reservation, $bob, $dish, 3, '9.25');

        $summary = $this->service->summarize($reservation);

        $this->assertCount(1, $summary['items']);
        $this->assertSame(5, $summary['items'][0]['quantity']);
        $this->assertSame('44.75', $summary['items'][0]['subtotal']);
        $this->assertSame(['17.00', '27.75'], array_column($summary['guests'], 'subtotal'));
        $this->assertSame('8.50', $summary['guests'][0]['items'][0]['unit_price']);
        $this->assertSame('9.25', $summary['guests'][1]['items'][0]['unit_price']);
        $this->assertSame('44.75', $summary['grand_total']);
    }

    public function test_fractional_prices_and_free_items_keep_exact_two_digit_decimal_strings(): void
    {
        $reservation = $this->createReservation();
        $guest = $reservation->guests()->create(['name' => 'Alice']);
        $this->addItem($reservation, $guest, $this->createDish('Small item'), 3, '0.10');
        $this->addItem($reservation, $guest, $this->createDish('Another item'), 2, '0.20');
        $this->addItem($reservation, $guest, $this->createDish('Complimentary item'), 1, '0.00');

        $summary = $this->service->summarize($reservation);

        $this->assertSame(['0.30', '0.40', '0.00'], array_column($summary['items'], 'subtotal'));
        $this->assertSame(6, $summary['total_quantity']);
        $this->assertSame('0.70', $summary['guests'][0]['subtotal']);
        $this->assertSame('0.70', $summary['grand_total']);
    }

    public function test_money_totals_can_exceed_native_integer_cents_without_losing_precision(): void
    {
        $reservation = $this->createReservation();
        $guest = $reservation->guests()->create(['name' => 'Alice']);
        $this->addItem($reservation, $guest, $this->createDish('Large order'), 4_000_000_000, '99999999.99');

        $summary = $this->service->summarize($reservation);

        $this->assertSame(4_000_000_000, $summary['total_quantity']);
        $this->assertSame('399999999960000000.00', $summary['items'][0]['subtotal']);
        $this->assertSame('399999999960000000.00', $summary['guests'][0]['subtotal']);
        $this->assertSame('399999999960000000.00', $summary['grand_total']);
    }

    public function test_items_are_isolated_by_both_pre_order_and_guest_reservations(): void
    {
        $reservation = $this->createReservation();
        $otherReservation = $this->createReservation();
        $alice = $reservation->guests()->create(['name' => 'Alice']);
        $outsider = $otherReservation->guests()->create(['name' => 'Outside guest']);
        $dish = $this->createDish('Garden Salad');
        $this->addItem($reservation, $alice, $dish, 1, '8.50');
        $this->addItem($otherReservation, $outsider, $dish, 100, '8.50');

        // These mismatched links are allowed by the individual foreign keys.
        $this->addItem($reservation, $outsider, $dish, 50, '9.00');
        $this->addItem($otherReservation, $alice, $dish, 25, '10.00');
        $preOrder = $reservation->preOrder()->firstOrFail();

        // A caller's cached relation must not override the reservation scope.
        $reservation->setRelation('preOrder', $otherReservation->preOrder()->firstOrFail());
        $summary = $this->service->summarize($reservation);

        $this->assertSame($preOrder->id, $summary['pre_order_id']);
        $this->assertCount(1, $summary['items']);
        $this->assertSame(1, $summary['items'][0]['quantity']);
        $this->assertSame([$alice->id], array_column($summary['guests'], 'reservation_guest_id'));
        $this->assertSame(1, $summary['total_quantity']);
        $this->assertSame('8.50', $summary['grand_total']);
    }

    public function test_eager_loading_keeps_query_count_constant_as_guests_and_dishes_increase(): void
    {
        $reservation = $this->createReservation();
        $guest = $reservation->guests()->create(['name' => 'Alice']);
        $this->addItem($reservation, $guest, $this->createDish('First dish'), 1, '1.00');
        $initialQueryCount = $this->summaryQueryCount($reservation);

        foreach (range(1, 10) as $index) {
            $guest = $reservation->guests()->create(['name' => 'Guest '.$index]);
            $this->addItem($reservation, $guest, $this->createDish('Dish '.$index), 1, '1.00');
        }

        $this->assertLessThanOrEqual(4, $initialQueryCount);
        $this->assertSame($initialQueryCount, $this->summaryQueryCount($reservation));
        $this->assertSame(11, $this->service->summarize($reservation)['total_quantity']);
    }

    private function createReservation(): Reservation
    {
        return $this->restaurant->reservations()->create([
            'owner_user_id' => $this->owner->id,
            'code' => (string) Str::uuid(),
            'reserved_at' => now()->addDay(),
            'guest_count' => 2,
        ]);
    }

    private function createDish(string $name): MenuItem
    {
        return $this->category->items()->create(['name' => $name, 'price' => '12.50']);
    }

    private function addItem(Reservation $reservation, ReservationGuest $guest, MenuItem $dish, int $quantity, string $unitPrice): void
    {
        $preOrder = $reservation->preOrder()->firstOrCreate([], ['status' => 'draft']);
        $preOrder->items()->create([
            'reservation_guest_id' => $guest->id,
            'menu_item_id' => $dish->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);
    }

    private function summaryQueryCount(Reservation $reservation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->service->summarize($reservation);

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
