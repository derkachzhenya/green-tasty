<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GroupPreOrderSubmissionService;
use App\Services\RestaurantPreOrderReviewService;
use App\Services\WaiterReservationService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class RestaurantReviewConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private ?Connection $heldConnection = null;

    private bool $databasePrepared = false;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL row locks required.');
        }
        $this->assertSame('testing', DB::connection()->getDatabaseName());
    }

    public function test_competing_review_and_cancellation_wait_then_reject_the_stale_version(): void
    {
        $this->databasePrepared = true;
        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Race', 'slug' => 'race', 'address' => 'Test']);
        $restaurant->refresh();
        $staff->staffRestaurants()->attach($restaurant, ['role' => 'waiter', 'is_active' => true]);
        $reservation = $restaurant->reservations()->create(['owner_user_id' => $owner->id, 'code' => 'GT-REVIEW-RACE', 'reserved_at' => now()->addDay(), 'status' => 'confirmed', 'guest_count' => 1]);
        $guest = $reservation->guests()->create(['user_id' => $owner->id, 'name' => 'Owner']);
        $category = $restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $dish = $category->items()->create(['name' => 'Dish', 'price' => '12.00']);
        $order = $reservation->preOrder()->create(['status' => 'draft']);
        $item = $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => 1, 'unit_price' => '10.00']);
        app(GroupPreOrderSubmissionService::class)->submit($reservation, $owner);
        $service = app(RestaurantPreOrderReviewService::class);
        $old = $service->view($restaurant, $reservation, $staff)['version'];
        $this->holdOperation(fn () => $service->decide(Restaurant::findOrFail($restaurant->id), Reservation::findOrFail($reservation->id), User::findOrFail($staff->id), $item->id, 'confirmed', $old));
        $this->assertBlockedByRowLock(fn () => $service->decide($restaurant, $reservation, $staff, $item->id, 'rejected', $old));
        $this->assertBlockedByRowLock(fn () => app(WaiterReservationService::class)->cancel($restaurant, $reservation, $staff, $old));
        $this->heldConnection->commit();
        try {
            $service->decide($restaurant, $reservation, $staff, $item->id, 'rejected', $old);
            $this->fail('Stale decision overwrote committed result');
        } catch (ConflictHttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $fresh = $service->version($reservation->fresh());
        $this->holdOperation(fn () => $service->submit(Restaurant::findOrFail($restaurant->id), Reservation::findOrFail($reservation->id), User::findOrFail($staff->id), $fresh));
        $this->assertBlockedByRowLock(fn () => $service->submit($restaurant, $reservation, $staff, $fresh));
        $this->heldConnection->commit();
        $service->submit($restaurant, $reservation, $staff, $fresh);
        $this->assertDatabaseCount('pre_order_review_rounds', 1);
        $this->assertSame('confirmed', $item->fresh()->restaurant_decision);
        $this->assertSame('10.00', $item->fresh()->unit_price);
    }

    protected function tearDown(): void
    {
        if ($this->heldConnection !== null) {
            while ($this->heldConnection->transactionLevel() > 0) {
                $this->heldConnection->rollBack();
            } DB::purge('submission_race');
        }
        if ($this->databasePrepared) {
            $this->truncateTablesForAllConnections();
        }
        parent::tearDown();
    }

    private function holdOperation(callable $operation): mixed
    {
        $default = config('database.default');
        config(['database.connections.submission_race' => config('database.connections.'.$default)]);
        $this->heldConnection = DB::connection('submission_race');
        $this->heldConnection->beginTransaction();

        try {
            config(['database.default' => 'submission_race']);

            return $operation();
        } finally {
            config(['database.default' => $default]);
        }
    }

    private function assertBlockedByRowLock(callable $operation): void
    {
        $connection = DB::connection();
        $timeout = $connection->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            try {
                $operation();
                $this->fail('The competing transaction must wait for the reservation row lock.');
            } catch (QueryException $exception) {
                $this->assertSame(1205, $exception->errorInfo[1]);
                $this->assertSame(0, $connection->transactionLevel());
            }
        } finally {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = '.(int) $timeout);
        }
    }
}
