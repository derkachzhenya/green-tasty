<?php

namespace Tests\Feature;

use App\Models\GuestPreOrderRound;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GuestAdditionalPreOrderService;
use App\Services\GuestInvitationService;
use App\Services\MyReservationsService;
use App\Services\PreOrderCutoffService;
use App\Services\PreOrderTiming;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreOrderCutoffTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Reservation $reservation;

    private MenuItem $dish;

    private string $token;

    private string $own;

    private string $group;

    private CarbonImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->start = CarbonImmutable::parse('2026-11-01T00:15:00+04:00')->utc();
        $this->travelTo($this->start->subHour());
        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Garden', 'slug' => 'garden', 'address' => 'Garden address']);
        $this->reservation = $restaurant->reservations()->create(['owner_user_id' => $this->owner->id, 'code' => 'GT-CUTOFF', 'reserved_at' => $this->start, 'status' => 'confirmed', 'guest_count' => 4]);
        $this->reservation->guests()->create(['user_id' => $this->owner->id, 'name' => 'Owner']);
        $category = $restaurant->menuCategories()->create(['name' => 'Appetizers', 'slug' => 'appetizers']);
        $this->dish = $category->items()->create(['name' => 'Salad', 'price' => '17.00']);
        $invite = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Guest']);
        $this->token = basename($invite['invitation_url']);
        $this->own = '/reservations/'.$this->reservation->id.'/owner-pre-order';
        $this->group = '/reservations/'.$this->reservation->id.'/pre-order/owner-view';
        $this->actingAs($this->owner);
        $this->putJson($this->own.'/selections', $this->items(), ['If-Match' => '"0"'])->assertOk();
        $this->putJson('/invitations/'.$this->token.'/selections', $this->items(), ['If-Match' => '"0"'])->assertOk();
    }

    private function items(int $quantity = 1): array
    {
        return ['items' => [['menu_item_id' => $this->dish->id, 'quantity' => $quantity]]];
    }

    public static function incompleteCutoffSchemas(): array
    {
        return [
            'before migration' => [['pre_order_items', 'guest_pre_order_rounds']],
            'items column missing' => [['pre_order_items']],
            'rounds column missing' => [['guest_pre_order_rounds']],
        ];
    }

    #[DataProvider('incompleteCutoffSchemas')]
    public function test_reads_survive_an_unapplied_or_partial_cutoff_migration_without_changing_selections(array $tables): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Schema rollback in this test requires transactional SQLite DDL.');
        }
        $guest = $this->reservation->guests()->whereNull('user_id')->firstOrFail();
        $round = GuestPreOrderRound::create(['reservation_guest_id' => $guest->id, 'number' => 2, 'items' => [
            ['menu_item_id' => $this->dish->id, 'name' => 'Salad', 'quantity' => 2, 'unit_price' => '17.00'],
        ]]);
        $order = $this->reservation->preOrder;
        $order->update(['status' => 'submitted', 'submitted_at' => now()]);
        $order->items()->update(['restaurant_decision' => 'confirmed', 'review_published_at' => now()]);
        foreach ($tables as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('cancelled_at'));
            $this->assertFalse(Schema::hasColumn($table, 'cancelled_at'));
        }
        $versions = $this->reservation->guests()->pluck('selection_version', 'id')->all();
        $this->assertFalse(app(PreOrderCutoffService::class)->available());
        $this->travelTo($this->start->subMinutes(45));
        // Only the additional draft is unconfirmed; exercise the round query too.
        $this->assertTrue(PreOrderTiming::state($this->reservation)['unconfirmed']);
        $this->assertSame($round->id, app(GuestAdditionalPreOrderService::class)->draft($guest)?->id);
        $this->travelTo($this->start->subMinutes(30));
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservations.total', 1)
                ->where('reservations.data.0.id', $this->reservation->id)));
        $this->getJson($this->group)->assertOk()->assertJsonPath('summary.grand_total', '34.00')->assertJsonPath('cancelled_items', []);
        $this->getJson($this->own.'/menu')->assertOk()->assertJsonPath('selections.subtotal', '17.00');
        $this->getJson('/invitations/'.$this->token.'/menu')->assertOk()->assertJsonPath('selections.subtotal', '34.00');
        $staff = User::factory()->create();
        $staff->staffRestaurants()->attach($this->reservation->restaurant_id, ['role' => 'waiter', 'is_active' => true]);
        $root = '/staff/restaurants/'.$this->reservation->restaurant_id.'/reservations';
        $this->actingAs($staff)->get($root)->assertOk();
        $this->getJson($root.'/'.$this->reservation->id.'/review')->assertOk()->assertJsonPath('totals.confirmed', '34.00');
        $this->artisan('pre-orders:expire')->expectsOutput('Cancelled 0 unconfirmed pre-order items.')->assertSuccessful();
        $this->assertSame(2, $order->items()->whereNull('superseded_at')->count());
        $this->assertSame($versions, $this->reservation->guests()->pluck('selection_version', 'id')->all());
        $this->assertSame($round->items, $round->fresh()->items);
        $this->assertDatabaseCount('pre_order_items', 2);
        $this->assertDatabaseCount('guest_pre_order_rounds', 1);
    }

    public function test_warning_window_is_server_derived_refreshable_owner_scoped_and_has_no_t30_dashboard_notice(): void
    {
        $service = app(MyReservationsService::class);
        $this->travelTo($this->start->subMinutes(45)->subSecond());
        $this->assertSame([], $service->warnings($this->owner));
        $this->travelTo($this->start->subMinutes(45));
        $this->assertSame([['id' => $this->reservation->id, 'code' => 'GT-CUTOFF', 'minutes' => 15]], $service->warnings($this->owner));
        $this->get(route('dashboard'))->assertOk()->assertViewHas('page', fn ($page) => $page['props']['preOrderWarnings'][0]['code'] === 'GT-CUTOFF');
        $this->assertSame([], $service->warnings(User::factory()->create()));
        $this->travelTo($this->start->subMinutes(30)->subSecond());
        $this->assertSame(1, $service->warnings($this->owner)[0]['minutes']);
        $this->travelTo($this->start->subMinutes(30));
        $this->assertSame([], $service->warnings($this->owner));
        $this->getJson($this->group)->assertOk()->assertJsonPath('timing.locked', true)->assertJsonPath('editable', false)->assertJsonPath('summary.grand_total', '0.00')->assertJsonCount(2, 'cancelled_items');
        $this->getJson($this->own.'/menu')->assertOk()->assertJsonPath('order.editable', false)->assertJsonPath('context.date', '2026-11-01')->assertJsonPath('context.time', '00:15');
    }

    public function test_exact_cutoff_blocks_writes_and_read_cancels_unconfirmed_items_without_deleting_history(): void
    {
        $view = $this->getJson($this->group)->assertOk()->json();
        $ownItem = $view['participants'][0]['items'][0]['id'];
        $guest = $this->reservation->guests()->whereNull('user_id')->firstOrFail();
        $before = $this->reservation->preOrder->items()->orderBy('id')->get()->toJson();
        $this->travelTo($this->start->subMinutes(30));
        $this->putJson($this->own.'/selections', $this->items(3), ['If-Match' => '"1"'])->assertConflict();
        $this->postJson($this->own.'/submit', $this->items(3), ['If-Match' => '"1"'])->assertConflict();
        $this->putJson('/invitations/'.$this->token.'/selections', $this->items(2), ['If-Match' => '"1"'])->assertConflict();
        $this->postJson('/invitations/'.$this->token.'/identity', ['name' => 'Changed'])->assertConflict();
        $this->postJson('/invitations/'.$this->token.'/submit', $this->items(2), ['If-Match' => '"1"'])->assertConflict();
        $headers = ['If-Match' => $view['version']];
        $this->patchJson($this->group.'/items/'.$ownItem, ['quantity' => 2], $headers)->assertConflict();
        $this->deleteJson($this->group.'/guests/'.$guest->id, [], $headers)->assertConflict();
        $this->postJson($this->group.'/submit', [], $headers)->assertConflict();
        $this->postJson('/reservations/'.$this->reservation->id.'/pre-order/submit')->assertConflict();
        $this->postJson('/reservations/'.$this->reservation->id.'/invitations', ['name' => 'New guest'])->assertConflict();
        $this->postJson('/reservations/'.$this->reservation->id.'/invitations/'.$guest->id.'/regenerate')->assertConflict();
        $this->deleteJson('/reservations/'.$this->reservation->id.'/invitations/'.$guest->id)->assertConflict();
        $this->assertSame($before, $this->reservation->preOrder->items()->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('reservation_guests', 2);
        $this->assertDatabaseHas('pre_orders', ['id' => $this->reservation->preOrder->id, 'status' => 'draft', 'submitted_at' => null]);
        $this->assertNull($guest->fresh()->invitation_revoked_at);
        $this->getJson('/invitations/'.$this->token.'/menu')->assertOk()->assertJsonPath('order.editable', false)->assertJsonPath('selections.subtotal', '0.00');
        $this->assertSame(2, $this->reservation->preOrder->items()->whereNotNull('cancelled_at')->count());
        $this->assertSame(2, $this->reservation->preOrder->items()->count());
    }

    public function test_last_second_before_boundary_allows_atomic_owner_edit_and_keeps_historical_price(): void
    {
        $this->postJson($this->own.'/submit', $this->items(), ['If-Match' => '"1"'])->assertOk();
        $this->dish->update(['price' => '999.00']);
        $this->travelTo($this->start->subMinutes(30)->subSecond());
        $this->assertFalse(PreOrderTiming::locked($this->reservation));
        $this->postJson($this->own.'/submit', $this->items(2), ['If-Match' => '"1"'])->assertOk()->assertJsonPath('selections.subtotal', '34.00')->assertJsonPath('guest_state.version', 2);
        $stamp = $this->reservation->guests()->where('user_id', $this->owner->id)->firstOrFail()->pre_order_submitted_at;
        $this->travelTo($this->start->subMinutes(30));
        $this->postJson($this->own.'/submit', $this->items(2), ['If-Match' => '"1"'])->assertOk();
        $this->postJson($this->own.'/submit', $this->items(3), ['If-Match' => '"2"'])->assertConflict();
        $this->assertTrue($stamp->equalTo($this->reservation->guests()->where('user_id', $this->owner->id)->firstOrFail()->pre_order_submitted_at));
    }

    public function test_owner_atomic_edit_is_versioned_and_revises_submitted_rows_without_touching_guest(): void
    {
        $this->postJson($this->own.'/submit', $this->items(), ['If-Match' => '"1"'])->assertOk();
        $this->postJson($this->own.'/submit', $this->items(3), ['If-Match' => '"0"'])->assertConflict();
        $this->postJson($this->own.'/submit', $this->items(2), ['If-Match' => '"1"'])->assertOk()->assertJsonPath('selections.subtotal', '34.00');
        $this->postJson($this->own.'/submit', $this->items(4), ['If-Match' => '"1"'])->assertConflict();
        $this->getJson('/invitations/'.$this->token.'/menu')->assertJsonPath('selections.subtotal', '17.00');
        $this->postJson('/reservations/'.$this->reservation->id.'/pre-order/submit')->assertOk();
        $this->postJson($this->own.'/submit', $this->items(3), ['If-Match' => '"2"'])->assertOk()->assertJsonPath('selections.subtotal', '51.00');
        $this->postJson($this->own.'/submit', $this->items(2), ['If-Match' => '"1"'])->assertConflict();
        $this->getJson('/invitations/'.$this->token.'/menu')->assertJsonPath('selections.subtotal', '17.00');
    }

    public function test_owner_can_save_empty_cart_without_creating_an_empty_confirmation(): void
    {
        $this->postJson($this->own.'/submit', ['items' => []], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('selections.subtotal', '0.00')->assertJsonPath('guest_state.submitted_at', null);
        $this->getJson($this->group)->assertJsonPath('summary.grand_total', '17.00');
    }

    public function test_warning_distinguishes_participant_confirmation_from_restaurant_confirmation(): void
    {
        $this->travelTo($this->start->subMinutes(45));
        $this->reservation->guests()->update(['pre_order_submitted_at' => now()]);
        $this->assertTrue(PreOrderTiming::state($this->reservation)['unconfirmed']);
        $this->reservation->guests()->update(['pre_order_submitted_at' => null]);
        $this->reservation->preOrder()->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->assertTrue(PreOrderTiming::state($this->reservation)['unconfirmed']);
        $this->reservation->preOrder->items()->update(['restaurant_decision' => 'confirmed', 'review_published_at' => now()]);
        $this->assertFalse(PreOrderTiming::state($this->reservation)['unconfirmed']);
    }

    public function test_expiry_keeps_published_confirmations_and_preserves_canceled_rows_and_draft_rounds(): void
    {
        $order = $this->reservation->preOrder;
        $order->update(['status' => 'submitted', 'submitted_at' => now()]);
        $own = $order->items()->whereRelation('guest', 'user_id', $this->owner->id)->firstOrFail();
        $own->forceFill(['restaurant_decision' => 'confirmed', 'review_published_at' => now()])->save();
        $guest = $this->reservation->guests()->whereNull('user_id')->firstOrFail();
        $rejected = $order->items()->where('reservation_guest_id', $guest->id)->firstOrFail();
        $rejected->forceFill(['restaurant_decision' => 'rejected', 'review_published_at' => now(), 'notes' => 'No onions'])->save();
        // A staff checkbox alone is not a published confirmation.
        $pending = $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $this->dish->id, 'quantity' => 2, 'unit_price' => '12.50', 'selection_round' => 2]);
        $pending->forceFill(['restaurant_decision' => 'confirmed'])->save();
        $round = GuestPreOrderRound::create(['reservation_guest_id' => $guest->id, 'number' => 2, 'items' => [
            ['menu_item_id' => $this->dish->id, 'name' => 'Salad', 'quantity' => 3, 'unit_price' => '17.00'],
        ]]);
        $historical = $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $this->dish->id, 'quantity' => 4, 'unit_price' => '9.00', 'selection_round' => 3]);
        $historical->forceFill(['superseded_at' => now()])->save();
        $originalHistory = $historical->fresh()->toJson();
        $originalRoundItems = $round->fresh()->items;
        $this->travelTo($this->start->subMinutes(30)->subSecond());
        $this->artisan('pre-orders:expire')->expectsOutput('Cancelled 0 unconfirmed pre-order items.')->assertSuccessful();
        $this->travelTo($this->start->subMinutes(30));
        $this->artisan('pre-orders:expire')->expectsOutput('Cancelled 2 unconfirmed pre-order items.')->assertSuccessful();
        $this->assertNull($own->fresh()->cancelled_at);
        $this->assertTrue($rejected->fresh()->cancelled_at->equalTo($this->start->subMinutes(30)));
        $this->assertNotNull($pending->fresh()->cancelled_at);
        $this->assertSame('17.00', $rejected->fresh()->unit_price);
        $this->assertSame('No onions', $rejected->fresh()->notes);
        $this->assertSame($originalHistory, $historical->fresh()->toJson());
        $this->assertSame($originalRoundItems, $round->fresh()->items);
        $this->assertNotNull($round->fresh()->cancelled_at);
        $this->assertNull(app(GuestAdditionalPreOrderService::class)->draft($guest));
        $this->getJson($this->group)->assertOk()->assertJsonPath('summary.grand_total', '17.00')->assertJsonCount(2, 'cancelled_items');
        $this->assertFalse(PreOrderTiming::state($this->reservation)['unconfirmed']);
        $versions = $this->reservation->guests()->pluck('selection_version', 'id')->all();
        $this->artisan('pre-orders:expire')->expectsOutput('Cancelled 0 unconfirmed pre-order items.')->assertSuccessful();
        $this->assertSame($versions, $this->reservation->guests()->pluck('selection_version', 'id')->all());
        $this->assertDatabaseCount('pre_order_items', 4);
        $this->assertDatabaseCount('guest_pre_order_rounds', 1);
        $this->assertSame(4, $this->reservation->fresh()->guest_count);
    }

    public function test_unauthorized_reads_do_not_expire_another_owners_order(): void
    {
        $this->travelTo($this->start->subMinutes(30));
        $other = User::factory()->create();
        $this->actingAs($other)->getJson($this->group)->assertForbidden();
        $this->getJson($this->own.'/menu')->assertForbidden();
        app(MyReservationsService::class)->forOwner($other);
        $this->assertSame(0, $this->reservation->preOrder->items()->whereNotNull('cancelled_at')->count());
        $this->actingAs($this->owner);
        app(MyReservationsService::class)->forOwner($this->owner);
        $this->assertSame(2, $this->reservation->preOrder->items()->whereNotNull('cancelled_at')->count());
    }

    public function test_terminal_reservations_are_not_rewritten_by_expiry(): void
    {
        $this->travelTo($this->start->subMinutes(30));
        foreach (['completed', 'cancelled'] as $status) {
            $this->reservation->update(['status' => $status]);
            $this->assertSame(0, app(PreOrderCutoffService::class)->expire($this->reservation));
            $this->assertSame(0, $this->reservation->preOrder->items()->whereNotNull('cancelled_at')->count());
        }
    }
}
