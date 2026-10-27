<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\BookingRules;
use App\Services\MyReservationsService;
use App\Services\ReservationCardState;
use App\Services\RestaurantBookingService;
use App\Services\RestaurantPreOrderReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingDurationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Restaurant $restaurant;

    private RestaurantTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00:00', 'UTC'));
        $this->owner = User::factory()->create();
        $this->restaurant = Restaurant::factory()->create(['name' => 'Duration restaurant', 'slug' => 'duration-restaurant', 'address' => 'Duration address']);
        $this->table = $this->restaurant->tables()->create(['name' => 'Duration table', 'seats' => 4]);
        $this->actingAs($this->owner);
    }

    private function start(string $time, string $date = '2026-11-13'): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time, BookingRules::TIMEZONE)->utc();
    }

    private function book(string $time, int $duration = 120, string $date = '2026-11-13'): Reservation
    {
        return app(RestaurantBookingService::class)->book($this->restaurant, $this->owner, $this->start($time, $date), 2, $this->table->id, $duration);
    }

    private function payload(mixed $duration): array
    {
        return ['date' => '2026-11-13', 'time' => '14:00', 'guest_count' => 2, 'restaurant_table_id' => $this->table->id, 'duration_minutes' => $duration];
    }

    public static function durations(): array
    {
        return array_map(fn (int $minutes) => [$minutes], BookingRules::DURATIONS);
    }

    #[DataProvider('durations')]
    public function test_supported_duration_is_saved_displayed_and_editable(int $duration): void
    {
        $this->postJson(route('restaurants.bookings.store', $this->restaurant), $this->payload($duration))->assertRedirect();
        $reservation = Reservation::query()->sole();
        $code = $reservation->code;
        $this->assertSame($duration, $reservation->duration_minutes);
        $this->get(route('reservations.confirmation', $reservation))->assertInertia(fn (Assert $page) => $page
            ->where('reservation.end_time', $this->start('14:00')->addMinutes($duration)->setTimezone(BookingRules::TIMEZONE)->format('H:i')));
        $this->getJson('/reservations/'.$reservation->id.'/owner-pre-order/menu')->assertOk()
            ->assertJsonPath('context.end_time', $this->start('14:00')->addMinutes($duration)->setTimezone(BookingRules::TIMEZONE)->format('H:i'));

        $newDuration = $duration === 180 ? 90 : 180;
        $this->patchJson(route('reservations.booking.update', $reservation), ['time' => '14:00', 'guest_count' => 2, 'duration_minutes' => $newDuration])->assertRedirect();
        $reservation->refresh();
        $this->assertSame($newDuration, $reservation->duration_minutes);
        $this->assertSame($code, $reservation->code);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame($reservation->endsAt()->setTimezone(BookingRules::TIMEZONE)->format('H:i'), app(MyReservationsService::class)->forOwner($this->owner)['data'][0]['end_time']);
    }

    public function test_omitted_duration_keeps_the_legacy_two_hour_default(): void
    {
        $payload = $this->payload(120);
        unset($payload['duration_minutes']);
        $this->postJson(route('restaurants.bookings.store', $this->restaurant), $payload)->assertRedirect();
        $reservation = Reservation::query()->sole();
        $this->assertSame(120, $reservation->duration_minutes);
        $this->assertSame('16:00', $reservation->endsAt()->setTimezone(BookingRules::TIMEZONE)->format('H:i'));
        app(RestaurantBookingService::class)->update($reservation, $this->owner, '14:30', 2);
        $this->assertSame(120, $reservation->fresh()->duration_minutes);
    }

    public function test_schema_rollback_refuses_to_discard_a_non_default_interval(): void
    {
        $reservation = $this->book('14:00', 180);
        $migration = require database_path('migrations/2026_10_27_170000_add_duration_to_reservations_table.php');
        try {
            $migration->down();
            $this->fail('Rollback must not silently erase booked durations.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('non-default intervals', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('reservations', 'duration_minutes'));
        $this->assertSame(180, $reservation->fresh()->duration_minutes);
    }

    public static function invalidDurations(): array
    {
        return [[0], [-90], [60], [105], [181], [240], [1.5], ['invalid'], [null]];
    }

    #[DataProvider('invalidDurations')]
    public function test_invalid_duration_cannot_be_searched_created_edited_or_resumed(mixed $duration): void
    {
        $payload = $this->payload($duration);
        $search = ['date' => $payload['date'], 'time' => $payload['time'], 'guest_count' => 2, 'duration_minutes' => $duration];
        // JSON preserves explicit null; query strings omit it and legitimately use the default.
        if ($duration !== null) {
            $this->getJson(route('booking.availability', $search))->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
            $this->getJson(route('restaurants.availability', [$this->restaurant, ...$search]))->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        }
        $this->postJson(route('restaurants.bookings.store', $this->restaurant), $payload)->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        $this->postJson(route('booking.resume'), [...$search, 'restaurant' => $this->restaurant->slug, 'table' => $this->table->id])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        $reservation = $this->book('14:00');
        $this->patchJson(route('reservations.booking.update', $reservation), ['time' => '14:00', 'guest_count' => 2, 'duration_minutes' => $duration])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        $this->assertSame(120, $reservation->fresh()->duration_minutes);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_extension_rechecks_the_whole_interval_and_failed_edit_preserves_the_booking(): void
    {
        $reservation = $this->book('14:00', 90);
        $this->book('16:00', 90);
        $before = $reservation->fresh()->getRawOriginal();
        $this->patchJson(route('reservations.booking.update', $reservation), ['time' => '14:00', 'guest_count' => 2, 'duration_minutes' => 180])
            ->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertSame($before, $reservation->fresh()->getRawOriginal());
        // Adjacent half-open intervals may touch exactly at 16:00.
        $this->patchJson(route('reservations.booking.update', $reservation), ['time' => '13:00', 'guest_count' => 2, 'duration_minutes' => 180])->assertRedirect();
        $this->assertSame('16:00', $reservation->fresh()->endsAt()->setTimezone(BookingRules::TIMEZONE)->format('H:i'));
    }

    public function test_short_booking_cannot_overlap_the_last_hour_of_an_existing_three_hour_booking(): void
    {
        $this->book('14:00', 180);
        $this->postJson(route('restaurants.bookings.store', $this->restaurant), [...$this->payload(90), 'time' => '16:30'])
            ->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        $this->getJson(route('booking.availability', ['restaurant' => $this->restaurant->slug, 'date' => '2026-11-13', 'time' => '16:30', 'guest_count' => 2, 'duration_minutes' => 90]))->assertJsonCount(0, 'tables');
        $this->book('17:00', 90);
        $this->book('12:30', 90);
        $this->assertDatabaseCount('reservations', 3);
    }

    #[DataProvider('durations')]
    public function test_public_private_and_locked_creation_agree_for_every_slot_and_mixed_durations(int $duration): void
    {
        foreach ([['2026-11-12', '23:00', 180], ['2026-11-13', '08:00', 90], ['2026-11-13', '12:00', 180], ['2026-11-13', '20:00', 150], ['2026-11-14', '00:30', 90]] as [$date, $time, $minutes]) {
            $this->book($time, $minutes, $date);
        }
        $slots = $this->getJson(route('booking.availability', ['restaurant' => $this->restaurant->slug, 'date' => '2026-11-13', 'time' => 'all', 'guest_count' => 2, 'duration_minutes' => $duration]))->assertOk()->json('tables.0.slots');
        $this->assertCount(48, $slots);
        $service = app(RestaurantBookingService::class);
        foreach ($slots as $slot) {
            $start = $this->start($slot['time']);
            $available = $service->availableTables($this->restaurant, $start, 2, $duration) !== [];
            DB::beginTransaction();
            try {
                $accepted = true;
                try {
                    $service->book($this->restaurant, $this->owner, $start, 2, $this->table->id, $duration);
                } catch (ValidationException) {
                    $accepted = false;
                }
                $this->assertSame($slot['available'], $available, 'Private '.$slot['time'].' / '.$duration);
                $this->assertSame($slot['available'], $accepted, 'Creation '.$slot['time'].' / '.$duration);
            } finally {
                DB::rollBack();
            }
        }
        $this->assertDatabaseCount('reservations', 5);
    }

    public function test_midnight_end_dates_and_lifecycle_counts_use_the_saved_duration(): void
    {
        $reservation = $this->book('23:30', 180);
        $this->get(route('reservations.confirmation', $reservation))->assertInertia(fn (Assert $page) => $page->where('reservation.end_date', '2026-11-14')->where('reservation.end_time', '02:30'));
        $long = $reservation->replicate(['code'])->fill(['code' => 'DURATION-LONG', 'reserved_at' => now()->subMinutes(150), 'duration_minutes' => 180]);
        $long->save();
        $short = $reservation->replicate(['code'])->fill(['code' => 'DURATION-SHORT', 'reserved_at' => now()->subMinutes(90), 'duration_minutes' => 90]);
        $short->save();
        $this->assertSame('in_progress', ReservationCardState::phase($long));
        $this->assertSame('completed', ReservationCardState::phase($short));
        $this->assertSame(['all' => 3, 'upcoming' => 1, 'in_progress' => 1, 'completed' => 1, 'canceled' => 0], app(MyReservationsService::class)->counts($this->owner));
        $this->assertSame($long->id, app(MyReservationsService::class)->forOwner($this->owner, 1, 'in_progress')['data'][0]['id']);
        $this->assertSame($short->id, app(MyReservationsService::class)->forOwner($this->owner, 1, 'completed')['data'][0]['id']);
    }

    public function test_authentication_handoff_keeps_duration_without_automatically_booking(): void
    {
        auth()->logout();
        $this->post(route('booking.resume'), ['restaurant' => $this->restaurant->slug, 'date' => '2026-11-13', 'time' => '14:00', 'table' => $this->table->id, 'guest_count' => 2, 'duration_minutes' => 180])
            ->assertRedirect(route('login'))->assertSessionHas('url.intended', fn ($url) => str_contains($url, 'duration_minutes=180'));
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_submitted_pre_order_still_blocks_customer_duration_changes(): void
    {
        $reservation = $this->book('14:00', 180);
        $reservation->preOrder()->create(['status' => 'submitted', 'submitted_at' => now()]);
        $this->patchJson(route('reservations.booking.update', $reservation), ['time' => '14:00', 'guest_count' => 2, 'duration_minutes' => 90])
            ->assertUnprocessable();
        $this->assertSame(180, $reservation->fresh()->duration_minutes);
    }

    public function test_staff_edits_preserve_duration_and_see_overnight_occupancy(): void
    {
        $reservation = $this->book('23:30', 180);
        $staff = User::factory()->create();
        $staff->staffRestaurants()->attach($this->restaurant->id, ['role' => 'waiter', 'is_active' => true]);
        $this->actingAs($staff)->get(route('staff.reservations.index', [$this->restaurant, 'date' => '2026-11-14', 'time' => '01:00']))
            ->assertInertia(fn (Assert $page) => $page->where('reservations.data.0.id', $reservation->id)->where('reservations.data.0.end_time', '02:30'));
        $version = app(RestaurantPreOrderReviewService::class)->version($reservation);
        $this->patchJson(route('staff.reservations.update', [$this->restaurant, $reservation]), ['date' => '2026-11-13', 'time' => '23:30', 'guest_count' => 3, 'table_id' => $this->table->id], ['If-Match' => $version])->assertOk();
        $this->assertSame(180, $reservation->fresh()->duration_minutes);
    }
}
