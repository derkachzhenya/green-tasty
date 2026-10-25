<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\BookingRules;
use App\Services\ConsolidatedPreOrderService;
use App\Services\GroupPreOrderSubmissionService;
use App\Services\GuestDishSelectionService;
use App\Services\GuestInvitationService;
use App\Services\RestaurantBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RestaurantBookingTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private RestaurantTable $table;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00:00', 'UTC'));
        $this->owner = User::factory()->create();
        $this->restaurant = Restaurant::create(['name' => 'Green Tasty Test', 'slug' => 'booking', 'address' => 'Tbilisi']);
        $this->table = $this->restaurant->tables()->create(['name' => 'Table 1', 'seats' => 4]);
    }

    public function test_booking_form_exposes_explicit_local_rules_and_minimal_restaurant_data(): void
    {
        $this->actingAs($this->owner)->get(route('restaurants.bookings.create', $this->restaurant))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('bookings/Create')
                ->where('restaurant', $this->restaurant->only(['id', 'name', 'slug', 'address', 'phone']))
                ->where('bookingRules', [
                    'timezone' => 'Asia/Tbilisi', 'duration_minutes' => 120, 'slot_minutes' => 30, 'max_guests' => 255,
                    'min_date' => '2026-10-23', 'max_date' => '2027-01-21', 'default_date' => '2026-10-24',
                ])->missing('reservations')->missing('guests'));
    }

    public function test_booking_is_persisted_in_utc_before_redirect_and_confirmation_uses_restaurant_time(): void
    {
        $response = $this->actingAs($this->owner)->post($this->storeUrl(), $this->payload());
        $reservation = Reservation::query()->sole();
        $response->assertRedirect(route('reservations.confirmation', $reservation));
        $this->assertSame('2026-10-24 08:00:00', $reservation->reserved_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $reservation->reserved_at->timezoneName);
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame($this->owner->id, $reservation->owner_user_id);
        $this->assertSame($this->table->id, $reservation->restaurant_table_id);
        $this->assertSame(2, $reservation->guest_count);
        $this->assertMatchesRegularExpression('/^GT-[0-9A-Z]{26}$/', $reservation->code);
        $this->assertDatabaseHas('reservation_guests', ['reservation_id' => $reservation->id, 'user_id' => $this->owner->id, 'name' => $this->owner->name]);
        $this->assertDatabaseCount('pre_orders', 0);
        $this->get(route('reservations.confirmation', $reservation))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('bookings/Confirmation')
                ->where('reservation.date', '2026-10-24')->where('reservation.time', '12:00')
                ->where('reservation.end_time', '14:00')->where('reservation.timezone', 'Asia/Tbilisi')
                ->where('reservation.status', 'confirmed')->missing('reservation.owner_user_id')
                ->missing('reservation.guests')->missing('reservation.special_requests'));
    }

    public function test_all_booking_routes_require_authentication(): void
    {
        $this->get(route('restaurants.bookings.create', $this->restaurant))->assertRedirect(route('login'));
        $this->getJson($this->availabilityUrl())->assertUnauthorized();
        $this->postJson($this->storeUrl(), $this->payload())->assertUnauthorized();
        $this->get(route('reservations.confirmation', 999))->assertRedirect(route('login'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_only_the_owner_can_read_the_confirmation(): void
    {
        $reservation = $this->existing();
        $this->actingAs(User::factory()->create())->getJson(route('reservations.confirmation', $reservation))
            ->assertForbidden()->assertDontSee($reservation->code);
        $this->getJson(route('reservations.confirmation', 999))->assertNotFound();
    }

    public function test_missing_restaurant_slugs_are_not_found(): void
    {
        $this->actingAs($this->owner)->get(route('restaurants.bookings.create', 'missing'))->assertNotFound();
        $this->postJson(route('restaurants.bookings.store', 'missing'), $this->payload())->assertNotFound();
    }

    public function test_inactive_restaurants_cannot_be_booked_or_queried(): void
    {
        $this->restaurant->update(['is_active' => false]);
        $this->actingAs($this->owner)->get(route('restaurants.bookings.create', $this->restaurant))->assertForbidden();
        $this->getJson($this->availabilityUrl())->assertForbidden();
        $this->postJson($this->storeUrl(), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_capacity_inactive_and_foreign_tables_are_rejected(): void
    {
        $other = Restaurant::create(['name' => 'Other', 'slug' => 'other', 'address' => 'Elsewhere']);
        $foreign = $other->tables()->create(['name' => 'Foreign', 'seats' => 6]);
        $inactive = $this->restaurant->tables()->create(['name' => 'Closed', 'seats' => 6, 'is_active' => false]);
        $this->actingAs($this->owner);
        foreach ([$foreign->id, $inactive->id, 999] as $id) {
            $this->postJson($this->storeUrl(), $this->payload(['restaurant_table_id' => $id]))
                ->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        }
        $this->postJson($this->storeUrl(), $this->payload(['guest_count' => 5]))
            ->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        $this->assertDatabaseCount('reservations', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_validation_rejects_invalid_dates_quantities_and_untrusted_fields(array $change, string $field): void
    {
        $this->actingAs($this->owner)->postJson($this->storeUrl(), $this->payload($change))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'missing date' => [['date' => null], 'date'],
            'impossible date' => [['date' => '2026-02-30'], 'date'],
            'past' => [['date' => '2026-10-22'], 'time'],
            'now' => [['date' => '2026-10-23', 'time' => '12:00'], 'time'],
            'beyond window' => [['date' => '2027-01-22'], 'date'],
            'invalid time' => [['time' => '25:00'], 'time'],
            'off grid' => [['time' => '12:15'], 'time'],
            'seconds' => [['time' => '12:00:01'], 'time'],
            'zero guests' => [['guest_count' => 0], 'guest_count'],
            'too many guests' => [['guest_count' => 256], 'guest_count'],
            'fractional guests' => [['guest_count' => 1.5], 'guest_count'],
            'text guests' => [['guest_count' => 'two'], 'guest_count'],
            'missing table' => [['restaurant_table_id' => null], 'restaurant_table_id'],
            'owner injection' => [['owner_user_id' => 100], 'owner_user_id'],
            'state injection' => [['status' => 'cancelled'], 'status'],
            'code injection' => [['code' => 'GT-CLIENT'], 'code'],
            'price injection' => [['price' => '0.01'], 'price'],
            'timezone injection' => [['timezone' => 'UTC'], 'timezone'],
        ];
    }

    public function test_availability_applies_the_same_schedule_validation(): void
    {
        $this->actingAs($this->owner)->getJson($this->availabilityUrl(['time' => '12:15']))
            ->assertUnprocessable()->assertJsonValidationErrors('time');
    }

    public function test_first_future_slot_and_last_window_day_are_allowed(): void
    {
        $this->actingAs($this->owner)->post($this->storeUrl(), $this->payload(['date' => '2026-10-23', 'time' => '12:30']))->assertRedirect();
        $this->post($this->storeUrl(), $this->payload(['date' => '2027-01-21', 'time' => '23:30']))->assertRedirect();
        $this->assertDatabaseCount('reservations', 2);
        $this->assertCount(2, Reservation::query()->pluck('code')->unique());
    }

    #[DataProvider('overlappingIntervals')]
    public function test_half_open_intervals_have_consistent_availability_and_booking_results(string $time, bool $conflict): void
    {
        $this->existing(); // 12:00–14:00 in Asia/Tbilisi.
        $this->actingAs($this->owner)->getJson($this->availabilityUrl(['time' => $time]))
            ->assertOk()->assertJsonCount($conflict ? 0 : 1, 'tables');
        $response = $this->postJson($this->storeUrl(), $this->payload(['time' => $time]));
        if ($conflict) {
            $response->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        } else {
            $response->assertRedirect();
        }
        $this->assertDatabaseCount('reservations', $conflict ? 1 : 2);
    }

    public static function overlappingIntervals(): array
    {
        return [
            'ends at start' => ['10:00', false], 'overlaps before' => ['10:30', true],
            'overlaps before by hour' => ['11:00', true], 'identical' => ['12:00', true],
            'inside' => ['12:30', true], 'overlaps after' => ['13:30', true],
            'starts at end' => ['14:00', false], 'later' => ['14:30', false],
        ];
    }

    #[DataProvider('reservationStatuses')]
    public function test_existing_reservation_statuses_block_conservatively(string $status, bool $blocks): void
    {
        $this->existing(['status' => $status]);
        $this->actingAs($this->owner)->getJson($this->availabilityUrl())->assertOk()->assertJsonCount($blocks ? 0 : 1, 'tables');
        $response = $this->postJson($this->storeUrl(), $this->payload());
        $blocks ? $response->assertUnprocessable() : $response->assertRedirect();
    }

    public static function reservationStatuses(): array
    {
        return [['draft', true], ['confirmed', true], ['pending', true], ['cancelled', false], ['completed', false]];
    }

    public function test_midnight_boundaries_store_the_previous_utc_day_and_display_the_correct_local_end(): void
    {
        $this->actingAs($this->owner)->post($this->storeUrl(), $this->payload(['time' => '00:30']))->assertRedirect();
        $reservation = Reservation::query()->sole();
        $this->assertSame('2026-10-23 20:30:00', $reservation->reserved_at->format('Y-m-d H:i:s'));
        $this->post($this->storeUrl(), $this->payload(['time' => '23:30']))->assertRedirect();
        $late = Reservation::query()->latest('id')->firstOrFail();
        $this->get(route('reservations.confirmation', $late))->assertInertia(fn (Assert $page) => $page
            ->where('reservation.date', '2026-10-24')->where('reservation.end_date', '2026-10-25')->where('reservation.end_time', '01:30'));
        $this->postJson($this->storeUrl(), $this->payload(['date' => '2026-10-25', 'time' => '00:30']))
            ->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
    }

    public function test_duplicate_submission_never_creates_a_second_booking_or_owner_participant(): void
    {
        $this->actingAs($this->owner)->post($this->storeUrl(), $this->payload())->assertRedirect();
        $this->postJson($this->storeUrl(), $this->payload())->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_availability_is_private_scoped_sorted_and_exposes_no_reservation_or_guest_data(): void
    {
        $large = $this->restaurant->tables()->create(['name' => 'Large', 'seats' => 6]);
        $this->restaurant->tables()->create(['name' => 'Too small', 'seats' => 1]);
        $this->restaurant->tables()->create(['name' => 'Inactive', 'seats' => 2, 'is_active' => false]);
        $other = Restaurant::create(['name' => 'Other', 'slug' => 'other', 'address' => 'Other']);
        $other->tables()->create(['name' => 'Private table', 'seats' => 6]);
        $this->actingAs($this->owner)->getJson($this->availabilityUrl())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['tables' => [
                ['id' => $this->table->id, 'name' => 'Table 1', 'seats' => 4],
                ['id' => $large->id, 'name' => 'Large', 'seats' => 6],
            ]]);
    }

    public function test_booking_and_owner_participant_are_created_atomically(): void
    {
        $failure = new RuntimeException('Simulated participant failure');
        $caught = null;
        $createdParticipant = null;

        // Fail after the INSERT on any database driver, while the booking transaction is still open.
        ReservationGuest::created(function (ReservationGuest $guest) use ($failure, &$createdParticipant): void {
            $createdParticipant = $guest;
            throw $failure;
        });

        try {
            app(RestaurantBookingService::class)->book($this->restaurant, $this->owner, app(BookingRules::class)->start('2026-10-24', '12:00'), 2, $this->table->id);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        // Assertions stay outside the catch: PHPUnit assertion failures also extend RuntimeException.
        $this->assertSame($failure, $caught, 'The booking must propagate the simulated participant failure.');
        $this->assertInstanceOf(ReservationGuest::class, $createdParticipant);
        $this->assertTrue($createdParticipant->exists);
        $this->assertNotNull($createdParticipant->getKey());
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_guests', 0);
    }

    public function test_booking_creation_is_rate_limited(): void
    {
        $this->actingAs($this->owner);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson($this->storeUrl(), [])->assertUnprocessable();
        }
        $this->postJson($this->storeUrl(), $this->payload())->assertTooManyRequests();
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_booking_post_retains_csrf_protection_outside_the_test_bypass(): void
    {
        $this->app->instance('env', 'local');
        $this->actingAs($this->owner)->postJson($this->storeUrl(), $this->payload())->assertStatus(419);
        $this->assertDatabaseCount('reservations', 0);
        $this->withSession(['_token' => 'booking-test-token'])->postJson($this->storeUrl(), $this->payload(['_token' => 'booking-test-token']))->assertRedirect();
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_a_new_booking_integrates_with_invitations_selections_and_group_submission(): void
    {
        $this->actingAs($this->owner)->post($this->storeUrl(), $this->payload())->assertRedirect();
        $reservation = Reservation::query()->sole();
        $category = $this->restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $dish = $category->items()->create(['name' => 'Bowl', 'price' => '12.50']);
        $invitation = app(GuestInvitationService::class)->create($reservation, ['name' => 'Alice']);
        $token = basename(parse_url($invitation['invitation_url'], PHP_URL_PATH));
        app(GuestDishSelectionService::class)->replace($token, [['menu_item_id' => $dish->id, 'quantity' => 2]]);
        $this->assertSame('25.00', app(ConsolidatedPreOrderService::class)->summarize($reservation)['grand_total']);
        $summary = app(GroupPreOrderSubmissionService::class)->submit($reservation, $this->owner);
        $this->assertSame('submitted', $summary['status']);
        $this->assertSame('25.00', $summary['grand_total']);
        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    private function existing(array $attributes = []): Reservation
    {
        return $this->restaurant->reservations()->create(array_merge([
            'restaurant_table_id' => $this->table->id, 'owner_user_id' => $this->owner->id,
            'code' => 'GT-EXISTING', 'reserved_at' => '2026-10-24 08:00:00', 'guest_count' => 2, 'status' => 'confirmed',
        ], $attributes));
    }

    private function payload(array $attributes = []): array
    {
        return array_merge(['date' => '2026-10-24', 'time' => '12:00', 'guest_count' => 2, 'restaurant_table_id' => $this->table->id], $attributes);
    }

    private function storeUrl(): string
    {
        return route('restaurants.bookings.store', $this->restaurant);
    }

    private function availabilityUrl(array $attributes = []): string
    {
        return route('restaurants.availability', array_merge(['restaurant' => $this->restaurant, 'date' => '2026-10-24', 'time' => '12:00', 'guest_count' => 2], $attributes));
    }
}
