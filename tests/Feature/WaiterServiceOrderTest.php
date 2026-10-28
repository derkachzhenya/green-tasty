<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\RestaurantPreOrderReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WaiterServiceOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $owner;

    private Restaurant $restaurant;

    private Reservation $reservation;

    private MenuItem $dish;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-28 09:00:00 UTC');
        $this->owner = User::factory()->create();
        $this->staff = User::factory()->create();
        $this->restaurant = Restaurant::factory()->create(['name' => 'Restaurant', 'slug' => 'service-order', 'address' => 'Actual address', 'is_active' => true]);
        $this->staff->staffRestaurants()->attach($this->restaurant, ['role' => 'waiter', 'is_active' => true]);
        $table = $this->restaurant->tables()->create(['name' => 'Table 1', 'seats' => 4]);
        $this->reservation = $this->restaurant->reservations()->create(['owner_user_id' => $this->owner->id, 'restaurant_table_id' => $table->id, 'code' => 'GT-SERVICE', 'reserved_at' => CarbonImmutable::now()->subMinutes(15), 'duration_minutes' => 120, 'guest_count' => 2, 'status' => 'confirmed']);
        $category = $this->restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $this->dish = $category->items()->create(['name' => 'Salad', 'price' => '10.50', 'is_available' => true]);
        $this->root = '/staff/restaurants/'.$this->restaurant->id.'/reservations/'.$this->reservation->id.'/order';
        $this->actingAs($this->staff);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function state(): array
    {
        return $this->getJson($this->root)->assertOk()->json();
    }

    private function save(array $items, ?string $version = null)
    {
        return $this->putJson($this->root, ['items' => $items], ['If-Match' => $version ?? $this->state()['version']]);
    }

    private function line(int $quantity = 2): array
    {
        return ['line_key' => 'new-'.$this->dish->id, 'menu_item_id' => $this->dish->id, 'quantity' => $quantity, 'notes' => 'No onions'];
    }

    public function test_read_does_not_create_an_order_and_staff_access_is_scoped_and_revocable(): void
    {
        $this->getJson($this->root)->assertOk()->assertJsonPath('editable', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('restaurant_service_orders', 0);
        $this->actingAs($this->owner)->getJson($this->root)->assertForbidden();
        $this->actingAs($this->owner)->putJson($this->root, ['items' => [$this->line()]], ['If-Match' => '"'.str_repeat('a', 64).'"'])->assertForbidden();
        $this->actingAs($this->staff);
        $other = Restaurant::factory()->create(['name' => 'Other', 'slug' => 'other', 'address' => 'Other address']);
        $this->staff->staffRestaurants()->attach($other, ['role' => 'waiter', 'is_active' => true]);
        $this->getJson(str_replace('/restaurants/'.$this->restaurant->id, '/restaurants/'.$other->id, $this->root))->assertNotFound();
        $version = $this->state()['version'];
        $this->staff->staffRestaurants()->updateExistingPivot($this->restaurant->id, ['is_active' => false]);
        $this->save([$this->line()], $version)->assertForbidden();
        $this->assertDatabaseCount('restaurant_service_orders', 0);
    }

    public function test_saves_server_prices_notes_and_immutable_revisions_and_blocks_stale_tabs(): void
    {
        $old = $this->state()['version'];
        $saved = $this->save([$this->line()], $old)->assertOk()->assertJsonPath('total', '21.00')->assertJsonPath('revision', 1)->assertJsonPath('items.0.notes', 'No onions')->json();
        $this->dish->update(['price' => '999.00']);
        $this->save([$this->line(3)], $old)->assertConflict();
        $this->save([$this->line(3)], $saved['version'])->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('total', '31.50');
        $this->assertDatabaseCount('restaurant_service_order_revisions', 2);
        $first = DB::table('restaurant_service_order_revisions')->where('revision', 1)->first();
        $this->assertSame(2, json_decode($first->items, true)[0]['quantity']);
        $this->assertSame('21.00', number_format((float) $first->total, 2, '.', ''));
        $this->assertSame($this->staff->id, $first->actor_user_id);
        $this->assertDatabaseCount('pre_orders', 0);
        $current = $this->state();
        $this->save([$this->line(3)], $current['version'])->assertOk()->assertJsonPath('revision', 2);
    }

    public function test_accepted_preorders_seed_distinct_price_lines_without_changing_customer_history(): void
    {
        $owner = $this->reservation->guests()->create(['user_id' => $this->owner->id, 'name' => 'Owner']);
        $guest = $this->reservation->guests()->create(['name' => 'Guest']);
        $preOrder = $this->reservation->preOrder()->create(['status' => 'submitted', 'submitted_at' => now(), 'review_revision' => 1, 'reviewed_revision' => 1]);
        $preOrder->forceFill(['review_revision' => 1, 'reviewed_revision' => 1])->save();
        foreach ([[$owner->id, '8.00', 'confirmed'], [$guest->id, '9.50', 'confirmed'], [$guest->id, '20.00', 'rejected']] as [$guestId, $price, $decision]) {
            $preOrder->items()->make(['reservation_guest_id' => $guestId, 'menu_item_id' => $this->dish->id, 'quantity' => 1, 'unit_price' => $price, 'notes' => 'Saved note', 'restaurant_decision' => $decision, 'selection_round' => $decision === 'rejected' ? 1 : 0])->forceFill(['restaurant_decision' => $decision, 'review_published_at' => now()])->save();
        }
        $before = $preOrder->items()->orderBy('id')->get()->toJson();
        $state = $this->state();
        $this->assertCount(2, $state['items']);
        $this->assertSame('17.50', $state['total']);
        $items = array_map(fn ($item) => array_intersect_key($item, array_flip(['line_key', 'menu_item_id', 'quantity', 'notes'])), $state['items']);
        $items[0]['quantity'] = 2;
        $this->save($items, $state['version'])->assertOk()->assertJsonPath('total', '25.50');
        $this->assertSame($before, $preOrder->items()->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('pre_order_review_rounds', 0);
    }

    public function test_unreviewed_customer_order_requires_review_first(): void
    {
        $this->reservation->preOrder()->create(['status' => 'submitted', 'submitted_at' => now()])->forceFill(['review_revision' => 2, 'reviewed_revision' => 1])->save();
        $this->getJson($this->root)->assertOk()->assertJsonPath('editable', false)->assertJsonPath('blocked_reason', 'Review and submit the customer pre-order before editing this order.');
        $this->save([$this->line()])->assertConflict();
    }

    public function test_service_order_write_requires_a_valid_csrf_session_token(): void
    {
        $this->app->instance('env', 'local');
        $this->withSession(['_token' => 'service-order-csrf']);
        $response = $this->getJson($this->root)->assertOk();
        $version = $response->json('version');
        $xsrf = $response->getCookie('XSRF-TOKEN', false)->getValue();
        $this->save([$this->line()], $version)->assertStatus(419);
        $this->putJson($this->root, ['items' => [$this->line()]], ['If-Match' => $version, 'X-XSRF-TOKEN' => $xsrf])->assertOk();
    }

    public function test_unavailable_foreign_duplicate_and_forged_price_lines_are_rejected(): void
    {
        $this->save([array_merge($this->line(), ['unit_price' => '0.01'])])->assertUnprocessable();
        $this->save([$this->line(), $this->line()])->assertUnprocessable();
        $this->save([array_merge($this->line(), ['line_key' => 'preorder-9999'])])->assertUnprocessable();
        $this->save([])->assertUnprocessable();
        $this->putJson($this->root, ['items' => [$this->line()]])->assertUnprocessable();
        $this->dish->update(['is_available' => false]);
        $this->save([$this->line()])->assertUnprocessable();
        $this->dish->update(['is_available' => true]);
        $this->save([$this->line()])->assertOk();
        $this->dish->update(['is_available' => false]);
        $this->save([$this->line(3)])->assertUnprocessable();
        $this->save([$this->line(1)])->assertOk()->assertJsonPath('total', '10.50');
        $this->dish->category->update(['restaurant_id' => Restaurant::factory()->create(['name' => 'Other', 'slug' => 'foreign', 'address' => 'Other'])->id]);
        $this->save([$this->line()])->assertUnprocessable();
    }

    public function test_in_progress_table_change_retains_legacy_seconds_and_service_order_history(): void
    {
        $this->save([$this->line()])->assertOk();
        $start = CarbonImmutable::now()->subMinutes(10)->setSecond(27);
        $this->reservation->update(['reserved_at' => $start]);
        $table = $this->restaurant->tables()->create(['name' => 'Table 2', 'seats' => 4]);
        $version = app(RestaurantPreOrderReviewService::class)->version($this->reservation->fresh());
        $local = $start->setTimezone('Asia/Tbilisi');
        $this->patchJson(dirname($this->root), ['date' => $local->format('Y-m-d'), 'time' => $local->format('H:i'), 'guest_count' => 2, 'table_id' => $table->id, 'duration_minutes' => 120], ['If-Match' => $version])->assertOk()->assertJsonPath('table_id', $table->id);
        $this->assertTrue($start->equalTo($this->reservation->fresh()->reserved_at));
        $this->assertDatabaseCount('restaurant_service_order_revisions', 1);
    }

    public function test_terminal_and_upcoming_phases_are_read_only_and_history_is_retained(): void
    {
        $saved = $this->save([$this->line()])->assertOk()->json();
        $this->reservation->update(['reserved_at' => CarbonImmutable::now()->subHours(3)]);
        $this->getJson($this->root)->assertOk()->assertJsonPath('phase', 'completed')->assertJsonPath('editable', false)->assertJsonPath('total', '21.00');
        $this->save([$this->line(3)], $saved['version'])->assertConflict();
        $this->reservation->update(['reserved_at' => CarbonImmutable::now()->addHours(3)]);
        $this->save([$this->line()])->assertConflict();
        $this->reservation->update(['status' => 'cancelled']);
        $this->getJson($this->root)->assertOk()->assertJsonPath('editable', false)->assertJsonPath('items.0.quantity', 2);
        $this->save([$this->line()])->assertConflict();
        $this->assertDatabaseCount('restaurant_service_order_revisions', 1);
    }
}
