<?php

namespace Tests\Feature;

use App\Models\PreOrder;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\BookingRules;
use App\Services\BookingSearchService;
use App\Services\GuestInvitationService;
use App\Services\RestaurantBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingFigmaFlowTest extends TestCase
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
        $this->restaurant = Restaurant::factory()->create(['name' => 'Real restaurant', 'slug' => 'location-1', 'address' => '48 Rustaveli Avenue']);
        $this->table = $this->restaurant->tables()->create(['name' => 'Garden table', 'seats' => 4]);
    }

    private function search(array $changes = []): string
    {
        return route('booking.availability', array_replace(['restaurant' => $this->restaurant->slug, 'date' => '2026-10-24', 'time' => '12:00', 'guest_count' => 2], $changes));
    }

    private function booking(): Reservation
    {
        return $this->actingAs($this->owner)->app->make(RestaurantBookingService::class)->book($this->restaurant, $this->owner, CarbonImmutable::parse('2026-10-24 08:00:00', 'UTC'), 2, $this->table->id);
    }

    private function edit(Reservation $reservation, array $changes = []): TestResponse
    {
        return $this->patchJson(route('reservations.booking.update', $reservation), array_replace(['time' => '15:00', 'guest_count' => 3, 'duration_minutes' => 120], $changes));
    }

    public function test_public_search_returns_real_tables_and_slots_without_customer_data_or_writes(): void
    {
        $this->get(route('booking.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('bookings/Create')->where('restaurant', null)->where('restaurants.0.id', $this->restaurant->id)->where('restaurants.0.address', '48 Rustaveli Avenue'));
        $this->getJson($this->search())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'tables')->assertJsonCount(48, 'tables.0.slots')
            ->assertJsonPath('tables.0.id', $this->table->id)->assertJsonPath('tables.0.restaurant.id', $this->restaurant->id)
            ->assertJsonPath('tables.0.slots.24', ['time' => '12:00', 'available' => true])
            ->assertJsonMissingPath('tables.0.reservations')->assertJsonMissingPath('tables.0.owner_user_id');
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_guests', 0);
        $this->assertGuest();
    }

    public function test_all_locations_preserves_table_ownership_and_excludes_inactive_or_small_tables(): void
    {
        $other = Restaurant::factory()->create(['name' => 'Other location', 'slug' => 'location-2', 'address' => '14 Baratashvili Street']);
        $otherTable = $other->tables()->create(['name' => 'Other table', 'seats' => 3]);
        $other->tables()->create(['name' => 'Small table', 'seats' => 1]);
        $other->tables()->create(['name' => 'Inactive table', 'seats' => 6, 'is_active' => false]);
        Restaurant::factory()->create(['name' => 'Closed', 'slug' => 'closed', 'address' => 'Closed address', 'is_active' => false])->tables()->create(['name' => 'Closed', 'seats' => 9]);
        $this->getJson($this->search(['restaurant' => null]))->assertOk()->assertJsonCount(2, 'tables')
            ->assertJsonPath('tables.1.id', $otherTable->id)->assertJsonPath('tables.1.restaurant.id', $other->id);
        $this->getJson($this->search(['restaurant' => $other->slug]))->assertOk()->assertJsonCount(1, 'tables')->assertJsonPath('tables.0.id', $otherTable->id);
        $this->getJson($this->search(['guest_count' => 5]))->assertOk()->assertJsonCount(0, 'tables');
    }

    public function test_real_conflicts_disable_overlaps_and_show_all_recovers_other_times(): void
    {
        $reservation = $this->booking();
        $this->getJson($this->search())->assertOk()->assertJsonCount(0, 'tables');
        $this->getJson($this->search(['time' => 'all']))->assertOk()->assertJsonCount(1, 'tables')
            ->assertJsonPath('tables.0.slots.20.available', true) // 10:00 ends exactly at 12:00.
            ->assertJsonPath('tables.0.slots.21.available', false)
            ->assertJsonPath('tables.0.slots.24.available', false)
            ->assertJsonPath('tables.0.slots.28.available', true); // 14:00 boundary.
        $reservation->update(['status' => 'cancelled']);
        $this->getJson($this->search())->assertJsonCount(1, 'tables');
    }

    public function test_today_show_all_disables_past_slots_without_rejecting_the_whole_day(): void
    {
        $this->getJson($this->search(['date' => '2026-10-23', 'time' => 'all']))->assertOk()
            ->assertJsonPath('tables.0.slots.24.available', false)->assertJsonPath('tables.0.slots.25.available', true);
    }

    #[DataProvider('invalidSearches')]
    public function test_search_validation_rejects_invalid_or_unsupported_input(array $changes, string $field): void
    {
        $this->getJson($this->search($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidSearches(): array
    {
        return [
            [['date' => ''], 'date'], [['date' => '2026-10-22'], 'date'], [['date' => '2027-01-22'], 'date'],
            [['time' => ''], 'time'], [['time' => '12:15'], 'time'], [['date' => '2026-10-23', 'time' => '12:00'], 'time'],
            [['guest_count' => 0], 'guest_count'], [['guest_count' => 256], 'guest_count'], [['restaurant' => 'missing'], 'restaurant'], [['owner_user_id' => 1], 'owner_user_id'],
        ];
    }

    public function test_guest_context_handoff_is_local_csrf_post_only_and_does_not_book_or_authenticate(): void
    {
        $data = ['restaurant' => $this->restaurant->slug, 'date' => '2026-10-24', 'time' => '12:00', 'guest_count' => 2, 'table' => $this->table->id, 'redirect' => 'https://evil.test'];
        $this->post(route('booking.resume'), $data)->assertRedirect(route('login'));
        $expected = route('booking.create', array_diff_key($data, ['redirect' => true]));
        $this->assertSame($expected, session('url.intended'));
        $this->assertGuest();
        $this->assertDatabaseCount('reservations', 0);
        $this->get(route('booking.resume'))->assertMethodNotAllowed();
        $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect($expected);
        $this->get($expected)->assertOk()->assertInertia(fn (Assert $page) => $page->where('initialSelection.table', (string) $this->table->id));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_guest_handoff_rejects_foreign_tables(): void
    {
        $foreign = Restaurant::factory()->create(['name' => 'Foreign', 'slug' => 'foreign', 'address' => 'Other'])->tables()->create(['name' => 'Foreign table', 'seats' => 4]);
        $this->postJson(route('booking.resume'), ['restaurant' => $this->restaurant->slug, 'date' => '2026-10-24', 'time' => '12:00', 'guest_count' => 2, 'table' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        $this->assertNull(session('url.intended'));
    }

    public function test_unverified_users_can_search_but_cannot_persist_or_edit(): void
    {
        $reservation = $this->booking();
        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->getJson($this->search())->assertOk();
        $this->postJson(route('restaurants.bookings.store', $this->restaurant), ['date' => '2026-10-24', 'time' => '16:00', 'guest_count' => 2, 'restaurant_table_id' => $this->table->id])->assertForbidden();
        $this->edit($reservation)->assertForbidden();
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_owner_edit_persists_utc_and_preserves_identity_guests_and_invitation(): void
    {
        $reservation = $this->booking();
        $invitation = app(GuestInvitationService::class)->create($reservation, ['name' => 'Actual guest']);
        $this->edit($reservation)->assertRedirect(route('reservations.confirmation', $reservation));
        $saved = $reservation->fresh();
        $this->assertSame('2026-10-24 11:00:00', $saved->reserved_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->restaurant->id, $saved->restaurant_id);
        $this->assertSame($this->table->id, $saved->restaurant_table_id);
        $this->assertSame($reservation->code, $saved->code);
        $this->assertSame(3, $saved->guest_count);
        $this->assertDatabaseCount('reservation_guests', 2);
        $token = basename($invitation['invitation_url']);
        $this->assertNotNull(app(GuestInvitationService::class)->resolve($token));
        $this->get(route('reservations.confirmation', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page->where('reservation.time', '15:00')->where('reservation.end_time', '17:00')->where('canEdit', true)->missing('invitation_url'));
    }

    public function test_edit_requires_owner_and_authentication(): void
    {
        $reservation = $this->booking();
        $this->actingAs(User::factory()->create());
        $this->edit($reservation)->assertForbidden();
        auth()->logout();
        $this->edit($reservation)->assertUnauthorized();
        $this->assertSame('08:00', $reservation->fresh()->reserved_at->format('H:i'));
    }

    public function test_edit_rejects_collisions_unsupported_duration_identity_injection_and_capacity(): void
    {
        $reservation = $this->booking();
        app(RestaurantBookingService::class)->book($this->restaurant, $this->owner, CarbonImmutable::parse('2026-10-24 11:00:00', 'UTC'), 2, $this->table->id);
        $this->edit($reservation)->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->edit($reservation, ['duration_minutes' => 90])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        $this->edit($reservation, ['restaurant_id' => 99])->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');
        $this->edit($reservation, ['restaurant_table_id' => 99])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        $this->edit($reservation, ['guest_count' => 5])->assertUnprocessable()->assertJsonValidationErrors('guest_count');
        app(GuestInvitationService::class)->create($reservation, ['name' => 'Guest']);
        $this->edit($reservation, ['guest_count' => 1])->assertUnprocessable()->assertJsonValidationErrors('guest_count');
        $this->assertSame(2, $reservation->fresh()->guest_count);
        $this->assertSame('08:00', $reservation->fresh()->reserved_at->format('H:i'));
    }

    public function test_edit_rejects_terminal_past_and_submitted_order_reservations(): void
    {
        $reservation = $this->booking();
        foreach (['cancelled', 'completed', 'draft'] as $status) {
            $reservation->update(['status' => $status]);
            $this->edit($reservation)->assertUnprocessable();
        }
        $reservation->update(['status' => 'confirmed', 'reserved_at' => now()->subDay()]);
        $this->edit($reservation)->assertUnprocessable();
        $reservation->update(['reserved_at' => '2026-10-24 08:00:00']);
        PreOrder::factory()->create(['reservation_id' => $reservation->id, 'status' => 'submitted']);
        $this->edit($reservation)->assertUnprocessable();
    }

    public function test_confirmation_get_does_not_issue_invitations_and_post_uses_existing_private_guest_flow(): void
    {
        $reservation = $this->booking();
        $this->get(route('reservations.confirmation', $reservation))->assertOk();
        $this->get(route('reservations.confirmation', $reservation))->assertOk();
        $this->assertDatabaseCount('reservation_guests', 1);
        $response = $this->postJson(route('reservations.invitations.store', $reservation), ['name' => 'Review guest'])->assertCreated();
        $this->assertSame($reservation->id, app(GuestInvitationService::class)->resolve(basename($response->json('invitation_url')))->reservation_id);
        $this->assertDatabaseMissing('reservation_guests', ['invitation_token' => basename($response->json('invitation_url'))]);
    }

    public function test_public_search_and_resume_are_rate_limited_by_ip(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson($this->search())->assertOk();
        }
        $this->getJson($this->search())->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.22'])->getJson($this->search())->assertOk();
    }

    public function test_handoff_and_edit_require_csrf_outside_the_testing_bypass(): void
    {
        $reservation = $this->booking();
        $this->app->instance('env', 'local');
        $this->edit($reservation)->assertStatus(419);
        $this->postJson(route('booking.resume'), ['restaurant' => $this->restaurant->slug])->assertStatus(419);
        $this->assertSame('08:00', $reservation->fresh()->reserved_at->format('H:i'));
        $this->withSession(['_token' => 'isolated-csrf-test'])->edit($reservation, ['_token' => 'isolated-csrf-test'])->assertRedirect();
    }

    #[DataProvider('parityDates')]
    public function test_every_displayed_slot_agrees_with_creation_and_private_availability(string $date): void
    {
        $this->actingAs($this->owner);
        $day = CarbonImmutable::parse($date, BookingRules::TIMEZONE);
        foreach ([[-30, 'confirmed'], [480, 'draft'], [720, 'confirmed'], [840, 'completed'], [960, 'cancelled'], [1500, 'confirmed']] as $index => [$minutes, $status]) {
            $this->restaurant->reservations()->create([
                'restaurant_table_id' => $this->table->id, 'owner_user_id' => $this->owner->id,
                'code' => 'GT-PARITY-'.$index, 'reserved_at' => $day->addMinutes($minutes)->utc(),
                'guest_count' => 2, 'status' => $status,
            ]);
        }
        $response = $this->getJson($this->search(['date' => $date, 'time' => 'all']))->assertOk();
        $slots = $response->json('tables.0.slots');
        $this->assertCount(48, $slots);
        $bookings = app(RestaurantBookingService::class);
        foreach ($slots as $slot) {
            $start = CarbonImmutable::parse($date.' '.$slot['time'], BookingRules::TIMEZONE)->utc();
            $privateAvailable = false;
            try {
                $privateAvailable = in_array($this->table->id, array_column($bookings->availableTables($this->restaurant, $start, 2), 'id'), true);
            } catch (ValidationException) {
                // A past start is unavailable rather than an allowed public slot.
            }
            DB::beginTransaction();
            try {
                $accepted = false;
                try {
                    $bookings->book($this->restaurant, $this->owner, $start, 2, $this->table->id);
                    $accepted = true;
                } catch (ValidationException) {
                    // Every other error must still fail this regression.
                }
                $this->assertSame($accepted, $slot['available'], $date.' '.$slot['time']);
                $this->assertSame($accepted, $privateAvailable, 'Private availability '.$slot['time']);
            } finally {
                DB::rollBack();
            }
        }
        $this->assertDatabaseCount('reservations', 6);
        $this->assertDatabaseCount('reservation_guests', 0);
    }

    public static function parityDates(): array
    {
        return [['2026-10-23'], ['2026-10-24'], ['2027-01-21']];
    }

    #[DataProvider('invalidServiceGuestCounts')]
    public function test_all_booking_services_reject_guest_counts_outside_the_global_limit(int $guests): void
    {
        $reservation = $this->booking();
        $start = CarbonImmutable::parse('2026-10-24 12:00:00', 'UTC');
        $bookings = app(RestaurantBookingService::class);
        foreach ([
            fn () => app(BookingSearchService::class)->search($this->restaurant, '2026-10-24', 'all', $guests),
            fn () => $bookings->availableTables($this->restaurant, $start, $guests),
            fn () => $bookings->book($this->restaurant, $this->owner, $start, $guests, $this->table->id),
            fn () => $bookings->update($reservation, $this->owner, '16:00', $guests),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Invalid guest count was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('guest_count', $exception->errors());
            }
        }
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame(2, $reservation->fresh()->guest_count);
    }

    public static function invalidServiceGuestCounts(): array
    {
        return [[0], [256], [999]];
    }

    public function test_ajax_search_keeps_the_booking_return_url_for_collision_validation(): void
    {
        $this->actingAs($this->owner)->get(route('booking.create'))->assertOk();
        $this->getJson($this->search(), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertSame(route('booking.create'), session('_previous.url'));
        $this->booking();
        $this->post(route('restaurants.bookings.store', $this->restaurant), [
            'date' => '2026-10-24', 'time' => '12:00', 'guest_count' => 2,
            'restaurant_table_id' => $this->table->id,
        ])->assertRedirect(route('booking.create'))->assertSessionHasErrors('restaurant_table_id');
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_json_sign_in_resumes_the_exact_selection_and_still_requires_explicit_confirmation(): void
    {
        $selection = ['restaurant' => $this->restaurant->slug, 'date' => '2026-10-24', 'time' => '14:30', 'guest_count' => 3, 'table' => $this->table->id];
        $expected = route('booking.create', $selection);
        $this->post(route('booking.resume'), $selection)->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
        $login = $this->postJson(route('login.store'), ['email' => $this->owner->email, 'password' => 'password'])->assertOk()
            ->assertJsonPath('next', 'authenticated')->assertJsonPath('redirect', $expected);
        $this->get($login->json('redirect'))->assertInertia(fn (Assert $page) => $page
            ->where('initialSelection.restaurant', $this->restaurant->slug)
            ->where('initialSelection.table', (string) $this->table->id)->where('initialSelection.time', '14:30')
            ->where('initialSelection.guest_count', '3'));
        $this->getJson($this->search(['time' => '14:30', 'guest_count' => 3]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJsonPath('tables.0.id', $this->table->id);
        $this->assertDatabaseCount('reservations', 0);
        $this->post(route('restaurants.bookings.store', $this->restaurant), [
            'date' => '2026-10-24', 'time' => '14:30', 'guest_count' => 3, 'restaurant_table_id' => $this->table->id,
        ])->assertRedirect();
        $saved = Reservation::query()->sole();
        $this->assertSame($this->table->id, $saved->restaurant_table_id);
        $this->assertSame($this->restaurant->id, $saved->restaurant_id);
        $this->assertSame('10:30', $saved->reserved_at->format('H:i'));
        $this->assertSame(3, $saved->guest_count);
    }

    public function test_edit_rejects_a_recorded_submission_even_if_its_status_is_stale(): void
    {
        $reservation = $this->booking();
        $reservation->preOrder()->create(['status' => 'draft', 'submitted_at' => now()]);
        $this->edit($reservation)->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertSame('08:00', $reservation->fresh()->reserved_at->format('H:i'));
        $this->get(route('reservations.confirmation', $reservation))->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    }

    public function test_edit_control_and_backend_agree_for_inactive_tables(): void
    {
        $reservation = $this->booking();
        $this->table->update(['is_active' => false]);
        $this->get(route('reservations.confirmation', $reservation))->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
        $this->edit($reservation)->assertUnprocessable()->assertJsonValidationErrors('time');
    }
}
