<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RestaurantBookingConcurrencyTest extends TestCase
{
    // Committed fixtures are visible to independent MySQL connections and the worker process.
    use DatabaseTruncation;

    private User $owner;

    private User $competitor;

    private Restaurant $restaurant;

    private RestaurantTable $table;

    private ?Connection $heldConnection = null;

    private ?Process $worker = null;

    private bool $databasePrepared = false;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires real MySQL row locks.');
        }
        $this->assertSame('testing', DB::connection()->getDatabaseName(), 'Concurrency tests must only use the isolated testing database.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->databasePrepared = true;
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00:00', 'UTC'));
        $this->owner = User::factory()->create();
        $this->competitor = User::factory()->create();
        $this->restaurant = Restaurant::create(['name' => 'Race test', 'slug' => 'booking-race', 'address' => 'Tbilisi']);
        $this->table = $this->restaurant->tables()->create(['name' => 'Table 1', 'seats' => 4]);
    }

    protected function tearDown(): void
    {
        $this->worker?->stop();
        if ($this->heldConnection !== null) {
            while ($this->heldConnection->transactionLevel() > 0) {
                $this->heldConnection->rollBack();
            }
            DB::purge('booking_race');
        }
        if ($this->databasePrepared) {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    public function test_competing_http_request_waits_on_the_table_lock_then_rejects_the_committed_booking(): void
    {
        $this->holdBookingRequest();
        $this->withoutExceptionHandling();
        $connection = DB::connection();
        $timeout = $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            try {
                $this->actingAs($this->competitor)->postJson($this->url(), $this->payload());
                $this->fail('A competing booking must wait for the table row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
                $this->assertSame(0, $connection->transactionLevel());
            }
        } finally {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $timeout);
            $this->withExceptionHandling();
        }
        $this->heldConnection->commit();
        $this->actingAs($this->competitor)->postJson($this->url(), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_guests', 1);
        $this->assertSame($this->owner->id, Reservation::query()->sole()->owner_user_id);
    }

    public function test_a_rolled_back_booking_does_not_reserve_the_table(): void
    {
        $this->holdBookingRequest();
        $this->heldConnection->rollBack();
        $this->actingAs($this->competitor)->postJson($this->url(), $this->payload())->assertRedirect();
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame($this->competitor->id, Reservation::query()->sole()->owner_user_id);
    }

    public function test_edit_waits_for_the_same_table_lock_and_rechecks_a_committed_conflict(): void
    {
        $this->actingAs($this->competitor)->postJson($this->url(), array_replace($this->payload(), ['time' => '16:00']))->assertRedirect();
        $reservation = Reservation::query()->sole();
        $this->holdBookingRequest(); // A competing 12:00 booking is not committed yet.
        $this->actingAs($this->competitor);
        $this->withoutExceptionHandling();
        $connection = DB::connection();
        $timeout = $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $payload = ['time' => '12:00', 'guest_count' => 2, 'duration_minutes' => 120];
        try {
            try {
                $this->patchJson(route('reservations.booking.update', $reservation), $payload);
                $this->fail('Editing must wait for the booking table lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }
        } finally {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $timeout);
            $this->withExceptionHandling();
        }
        $this->heldConnection->commit();
        $this->patchJson(route('reservations.booking.update', $reservation), $payload)->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertSame('12:00', $reservation->fresh()->reserved_at->format('H:i')); // 16:00 local, unchanged.
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_locking_current_read_sees_a_booking_committed_after_a_repeatable_read_snapshot(): void
    {
        $this->holdBookingRequest();
        DB::beginTransaction();
        try {
            $this->assertSame(0, Reservation::query()->count()); // Establish the contender's old snapshot.
            $this->heldConnection->commit();
            $this->actingAs($this->competitor)->postJson($this->url(), $this->payload())
                ->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_a_separate_http_worker_cannot_double_book_an_in_flight_booking(): void
    {
        $this->holdBookingRequest();
        $this->worker = new Process([
            PHP_BINARY, base_path('tests/Support/booking-request.php'),
            (string) $this->competitor->id, $this->restaurant->slug, json_encode($this->payload(), JSON_THROW_ON_ERROR),
        ], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->worker->setTimeout(15);
        $this->worker->start();
        $started = $this->worker->waitUntil(fn ($type, $output) => str_contains($output, 'attempting-table-lock'));
        $this->assertTrue($started, $this->worker->getOutput().$this->worker->getErrorOutput());
        $this->assertTrue($this->worker->isRunning());
        $this->heldConnection->commit();
        $this->worker->wait();
        $this->assertTrue($this->worker->isSuccessful(), $this->worker->getErrorOutput());
        $this->assertStringContainsString('response-status:422', $this->worker->getOutput());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_a_three_hour_booking_blocks_a_competing_late_start_after_the_lock_is_released(): void
    {
        $this->holdBookingRequest(['duration_minutes' => 180]);
        $this->worker = new Process([
            PHP_BINARY, base_path('tests/Support/booking-request.php'),
            (string) $this->competitor->id, $this->restaurant->slug,
            json_encode(array_replace($this->payload(), ['time' => '14:30', 'duration_minutes' => 90]), JSON_THROW_ON_ERROR),
        ], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);
        $this->worker->setTimeout(15);
        $this->worker->start();
        $this->assertTrue($this->worker->waitUntil(fn ($type, $output) => str_contains($output, 'attempting-table-lock')));
        $this->assertTrue($this->worker->isRunning());
        $this->heldConnection->commit();
        $this->worker->wait();
        $this->assertTrue($this->worker->isSuccessful(), $this->worker->getErrorOutput());
        $this->assertStringContainsString('response-status:422', $this->worker->getOutput());
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame(180, Reservation::query()->sole()->duration_minutes);
    }

    public function test_duration_extension_waits_for_and_rejects_a_concurrently_committed_next_booking(): void
    {
        $this->actingAs($this->competitor)->postJson($this->url(), array_replace($this->payload(), ['time' => '14:00', 'duration_minutes' => 90]))->assertRedirect();
        $reservation = Reservation::query()->sole();
        $this->holdBookingRequest(['time' => '16:00', 'duration_minutes' => 90]);
        $this->actingAs($this->competitor)->withoutExceptionHandling();
        $connection = DB::connection();
        $timeout = $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $payload = ['time' => '14:00', 'guest_count' => 2, 'duration_minutes' => 180];
        try {
            try {
                $this->patchJson(route('reservations.booking.update', $reservation), $payload);
                $this->fail('Duration changes must acquire the same table lock as booking creation.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
            }
        } finally {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $timeout);
            $this->withExceptionHandling();
        }
        $this->heldConnection->commit();
        $this->patchJson(route('reservations.booking.update', $reservation), $payload)->assertUnprocessable()->assertJsonValidationErrors('time');
        $this->assertSame(90, $reservation->fresh()->duration_minutes);
        $this->assertDatabaseCount('reservations', 2);
    }

    private function holdBookingRequest(array $changes = []): void
    {
        $default = config('database.default');
        config(['database.connections.booking_race' => config('database.connections.'.$default)]);
        $this->heldConnection = DB::connection('booking_race');
        $this->heldConnection->beginTransaction();
        try {
            config(['database.default' => 'booking_race']);
            $this->actingAs($this->owner)->postJson($this->url(), array_replace($this->payload(), $changes))->assertRedirect();
        } finally {
            config(['database.default' => $default]);
        }
    }

    private function url(): string
    {
        return route('restaurants.bookings.store', $this->restaurant);
    }

    private function payload(): array
    {
        return ['date' => '2026-10-24', 'time' => '12:00', 'guest_count' => 2, 'restaurant_table_id' => $this->table->id];
    }
}
