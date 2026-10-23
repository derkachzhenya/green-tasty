<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\PreOrder;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GroupPreOrderSubmissionService;
use App\Services\GuestDishSelectionService;
use App\Services\GuestInvitationService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GroupPreOrderSubmissionConcurrencyTest extends TestCase
{
    // Fixtures must be committed so both independent MySQL connections can see them.
    use DatabaseTruncation;

    private User $owner;

    private Reservation $reservation;

    private MenuItem $dish;

    private PreOrder $preOrder;

    private string $token;

    private ?Connection $heldConnection = null;

    private ?Process $worker = null;

    private bool $databasePrepared = false;

    protected function beforeTruncatingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('These tests require real MySQL row locks.');
        }
        $this->assertSame('testing', DB::connection()->getDatabaseName(), 'Concurrency tests must only truncate the isolated testing database.');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->databasePrepared = true;

        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Green Tasty', 'slug' => 'race-test', 'address' => 'Test']);
        $this->reservation = $restaurant->reservations()->create([
            'owner_user_id' => $this->owner->id, 'code' => 'GT-SUBMISSION-RACE',
            'reserved_at' => now()->addDay(), 'guest_count' => 1,
        ]);
        $category = $restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $this->dish = $category->items()->create(['name' => 'Vegetable Bowl', 'price' => '12.50']);
        $invitation = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Alice']);
        $this->token = basename(parse_url($invitation['invitation_url'], PHP_URL_PATH));
        app(GuestDishSelectionService::class)->replace($this->token, [['menu_item_id' => $this->dish->id, 'quantity' => 1]]);
        $this->preOrder = $this->reservation->preOrder()->firstOrFail();
    }

    protected function tearDown(): void
    {
        $this->worker?->stop();
        if ($this->heldConnection !== null) {
            while ($this->heldConnection->transactionLevel() > 0) {
                $this->heldConnection->rollBack();
            }
            DB::purge('submission_race');
        }

        if ($this->databasePrepared) {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_submission_waits_for_an_in_flight_guest_update_and_then_summarizes_its_committed_values(): void
    {
        $this->holdOperation(fn () => app(GuestDishSelectionService::class)->replace($this->token, [
            ['menu_item_id' => $this->dish->id, 'quantity' => 3],
        ]));

        $this->assertBlockedByRowLock(fn () => app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner));
        $this->assertSame('draft', $this->preOrder->fresh()->status);
        $this->assertNull($this->preOrder->fresh()->submitted_at);
        $this->heldConnection->commit();

        $summary = app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner);

        $this->assertSame('submitted', $summary['status']);
        $this->assertSame(3, $summary['total_quantity']);
        $this->assertSame('37.50', $summary['grand_total']);
        $this->assertDatabaseCount('pre_orders', 1);
    }

    public function test_uncommitted_submission_blocks_edits_and_duplicate_submission_then_becomes_idempotent(): void
    {
        $first = $this->holdOperation(fn () => app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner));

        $this->assertBlockedByRowLock(fn () => app(GuestDishSelectionService::class)->replace($this->token, [
            ['menu_item_id' => $this->dish->id, 'quantity' => 5],
        ]));
        $this->assertBlockedByRowLock(fn () => app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner));
        $this->heldConnection->commit();

        try {
            app(GuestDishSelectionService::class)->replace($this->token, []);
            $this->fail('Guest editing must fail after the submitted transaction commits.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame($first, app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner));
        $this->assertSame(1, $this->preOrder->items()->firstOrFail()->quantity);
        $this->assertDatabaseCount('pre_orders', 1);
    }

    public function test_separate_submission_http_worker_waits_for_guest_update_and_returns_the_committed_summary(): void
    {
        $this->holdOperation(fn () => app(GuestDishSelectionService::class)->replace($this->token, [
            ['menu_item_id' => $this->dish->id, 'quantity' => 3],
        ]));
        $this->worker = new Process([
            PHP_BINARY, base_path('tests/Support/submission-request.php'),
            (string) $this->owner->id, (string) $this->reservation->id,
        ], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->worker->setTimeout(15);
        $this->worker->start();
        $this->assertTrue(
            $this->worker->waitUntil(fn ($type, $output) => str_contains($output, 'attempting-reservation-lock')),
            $this->worker->getErrorOutput()
        );
        $this->assertTrue($this->worker->isRunning());
        $this->assertSame('draft', $this->preOrder->fresh()->status);
        $this->heldConnection->commit();
        $this->worker->wait();

        $this->assertTrue($this->worker->isSuccessful(), $this->worker->getErrorOutput());
        $this->assertStringContainsString('response-status:200', $this->worker->getOutput());
        $this->assertStringContainsString('grand-total:37.50', $this->worker->getOutput());
        $this->assertSame('submitted', $this->preOrder->fresh()->status);
        $this->assertSame(3, $this->preOrder->items()->sole()->quantity);
        $this->actingAs($this->owner)->postJson(route('reservations.pre-order.submit', $this->reservation))
            ->assertOk()->assertJsonPath('grand_total', '37.50');
        $this->putJson(route('invitations.selections.update', $this->token), ['items' => []])->assertConflict();
        $this->assertDatabaseCount('pre_orders', 1);
        $this->assertDatabaseCount('pre_order_items', 1);
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
