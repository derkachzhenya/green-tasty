<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GroupPreOrderSubmissionService;
use App\Services\RestaurantPreOrderReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WaiterPreOrderReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Restaurant $restaurant;

    private Reservation $reservation;

    private PreOrderItem $ownItem;

    private PreOrderItem $guestItem;

    private MenuItem $replacement;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->staff = User::factory()->create();
        $this->restaurant = Restaurant::factory()->create(['name' => 'Restaurant', 'slug' => fake()->unique()->slug(), 'address' => 'Test address', 'is_active' => true]);
        $this->staff->staffRestaurants()->attach($this->restaurant, ['role' => 'waiter', 'is_active' => true]);
        $table = $this->restaurant->tables()->create(['name' => 'Table 1', 'seats' => 4]);
        $this->reservation = $this->restaurant->reservations()->create(['restaurant_table_id' => $table->id, 'owner_user_id' => $this->owner->id, 'code' => 'GT-WAITER', 'reserved_at' => now()->addDays(2)->startOfDay()->addHours(12), 'guest_count' => 4, 'status' => 'confirmed']);
        $ownerGuest = $this->reservation->guests()->create(['user_id' => $this->owner->id, 'name' => 'Owner']);
        $guest = $this->reservation->guests()->create(['name' => 'Guest', 'pre_order_submitted_at' => now()]);
        $category = $this->restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $dish = $category->items()->create(['name' => 'Salad', 'price' => '999.00']);
        $this->replacement = $category->items()->create(['name' => 'Replacement', 'price' => '15.50']);
        $order = $this->reservation->preOrder()->create(['status' => 'draft']);
        $this->ownItem = $order->items()->create(['reservation_guest_id' => $ownerGuest->id, 'menu_item_id' => $dish->id, 'quantity' => 2, 'unit_price' => '10.00', 'notes' => 'Original note']);
        $this->guestItem = $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => 1, 'unit_price' => '12.00']);
        $this->actingAs($this->owner);
        app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner);
        $this->root = '/staff/restaurants/'.$this->restaurant->id.'/reservations/'.$this->reservation->id;
        $this->actingAs($this->staff);
    }

    private function version(): string
    {
        return $this->getJson($this->root.'/review')->assertOk()->json('version');
    }

    private function decide(PreOrderItem $item, string $decision, ?string $version = null)
    {
        return $this->patchJson($this->root.'/review/items/'.$item->id, ['decision' => $decision], ['If-Match' => $version ?? $this->version()]);
    }

    private function submit(?string $version = null)
    {
        return $this->postJson($this->root.'/review/submit', [], ['If-Match' => $version ?? $this->version()]);
    }

    private function correct(?string $version = null)
    {
        $this->actingAs($this->owner);
        $version ??= app(RestaurantPreOrderReviewService::class)->version($this->reservation->fresh());

        return $this->postJson('/reservations/'.$this->reservation->id.'/restaurant-review/items/'.$this->guestItem->id.'/replace', ['menu_item_id' => $this->replacement->id, 'quantity' => 2], ['If-Match' => $version]);
    }

    public function test_staff_access_is_explicit_restaurant_scoped_and_revocable(): void
    {
        $this->getJson($this->root.'/review')->assertOk()->assertJsonPath('status', 'Created by Customer');
        $this->actingAs($this->owner)->getJson($this->root.'/review')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson($this->root.'/review')->assertForbidden();
        $this->actingAs($this->staff);
        $foreign = Restaurant::factory()->create(['name' => 'Restaurant', 'slug' => fake()->unique()->slug(), 'address' => 'Test address', 'is_active' => true]);
        $this->getJson(str_replace('/restaurants/'.$this->restaurant->id, '/restaurants/'.$foreign->id, $this->root).'/review')->assertNotFound();
        $this->staff->staffRestaurants()->attach($foreign, ['role' => 'waiter', 'is_active' => true]);
        $this->getJson(str_replace('/restaurants/'.$this->restaurant->id, '/restaurants/'.$foreign->id, $this->root).'/review')->assertNotFound();
        $this->staff->staffRestaurants()->updateExistingPivot($this->restaurant->id, ['is_active' => false]);
        $this->getJson($this->root.'/review')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->root.'/review')->assertUnauthorized();
    }

    public function test_decisions_are_pending_until_published_and_all_items_must_be_decided(): void
    {
        $this->getJson($this->root.'/review')->assertJsonPath('totals.pending', '32.00')->assertJsonPath('can_submit', false);
        $this->submit()->assertUnprocessable();
        $this->decide($this->ownItem, 'confirmed')->assertOk();
        $this->actingAs($this->owner)->getJson('/reservations/'.$this->reservation->id.'/pre-order/owner-view')
            ->assertOk()->assertJsonPath('restaurant_review.participants.0.items.0.decision', 'pending');
        $this->actingAs($this->staff);
        $this->submit()->assertUnprocessable();
        $this->decide($this->guestItem, 'rejected')->assertJsonPath('can_submit', true);
        $this->submit()->assertOk()->assertJsonPath('status', 'Confirmed by Restaurant')->assertJsonPath('totals.confirmed', '20.00')->assertJsonPath('totals.rejected', '12.00');
        $this->assertDatabaseCount('pre_order_review_rounds', 1);
        $this->assertDatabaseHas('reservation_events', ['reservation_id' => $this->reservation->id, 'recipient_user_id' => $this->owner->id, 'kind' => 'items_rejected']);
        $this->assertDatabaseHas('pre_order_items', ['id' => $this->ownItem->id, 'unit_price' => '10.00', 'quantity' => 2, 'notes' => 'Original note']);
    }

    public function test_versions_invalid_decisions_and_foreign_items_cannot_bypass_checks(): void
    {
        $version = $this->version();
        foreach ([null, '*', 'W/'.$version, '"0"'] as $invalid) {
            $this->patchJson($this->root.'/review/items/'.$this->ownItem->id, ['decision' => 'confirmed'], $invalid ? ['If-Match' => $invalid] : [])->assertUnprocessable();
        }
        $this->decide($this->ownItem, 'accepted')->assertUnprocessable();
        $this->decide($this->ownItem, 'confirmed', $version)->assertOk();
        $this->decide($this->guestItem, 'rejected', $version)->assertConflict();
        $this->patchJson($this->root.'/review/items/999999', ['decision' => 'rejected'], ['If-Match' => $this->version()])->assertNotFound();
        $this->assertSame('pending', $this->guestItem->fresh()->restaurant_decision);
    }

    public function test_cross_reservation_item_and_stale_owner_correction_are_rejected(): void
    {
        $other = $this->restaurant->reservations()->create(['owner_user_id' => $this->owner->id, 'code' => 'GT-OTHER', 'reserved_at' => now()->addDays(3), 'guest_count' => 1]);
        $guest = $other->guests()->create(['name' => 'Other']);
        $order = $other->preOrder()->create(['status' => 'draft']);
        $foreign = $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $this->replacement->id, 'quantity' => 1, 'unit_price' => '8.00']);
        $this->decide($foreign, 'confirmed')->assertNotFound();
        $this->assertSame('pending', $foreign->fresh()->restaurant_decision);
        $this->decide($this->ownItem, 'confirmed');
        $this->decide($this->guestItem, 'rejected');
        $this->submit()->assertOk();
        $old = $this->version();
        $this->reservation->update(['guest_count' => 3]);
        $this->correct($old)->assertConflict();
        $this->assertNull($this->guestItem->fresh()->superseded_at);
        $this->actingAs($this->owner)->postJson('/reservations/'.$other->id.'/restaurant-review/items/'.$this->guestItem->id.'/replace', ['menu_item_id' => $this->replacement->id, 'quantity' => 1], ['If-Match' => app(RestaurantPreOrderReviewService::class)->version($other)])->assertConflict();
    }

    public function test_duplicate_decisions_and_submissions_do_not_duplicate_history_or_events(): void
    {
        $old = $this->version();
        $this->decide($this->ownItem, 'confirmed', $old)->assertOk();
        $stamp = $this->ownItem->fresh()->updated_at->toISOString();
        $this->decide($this->ownItem, 'confirmed', $old)->assertOk();
        $this->assertSame($stamp, $this->ownItem->fresh()->updated_at->toISOString());
        $this->decide($this->guestItem, 'confirmed')->assertOk();
        $version = $this->version();
        $this->submit($version)->assertOk();
        $events = DB::table('reservation_events')->count();
        $this->submit($version)->assertOk();
        $this->assertDatabaseCount('pre_order_review_rounds', 1);
        $this->assertSame($events, DB::table('reservation_events')->count());
        $this->decide($this->ownItem, 'rejected')->assertConflict();
    }

    public function test_owner_correction_appends_history_preserves_confirmations_and_requires_fresh_review(): void
    {
        $this->decide($this->ownItem, 'confirmed');
        $this->decide($this->guestItem, 'rejected');
        $this->submit()->assertOk();
        $price = $this->guestItem->unit_price;
        $this->correct()->assertOk()->assertJsonPath('status', 'Updated Pre-Order')->assertJsonPath('totals.requested', '51.00')->assertJsonPath('totals.pending', '31.00');
        $this->assertSame($price, $this->guestItem->fresh()->unit_price);
        $this->assertSame(1, $this->guestItem->fresh()->quantity);
        $this->assertNotNull($this->guestItem->fresh()->superseded_at);
        $this->assertDatabaseCount('pre_order_items', 3);
        $this->assertDatabaseHas('reservation_events', ['kind' => 'customer_updated', 'revision' => 2, 'audience' => 'restaurant']);
        $this->actingAs($this->staff);
        $this->submit()->assertUnprocessable();
        $new = PreOrderItem::where('menu_item_id', $this->replacement->id)->sole();
        $this->decide($new, 'confirmed')->assertOk();
        $this->submit()->assertOk()->assertJsonPath('totals.confirmed', '51.00');
        $this->assertDatabaseCount('pre_order_review_rounds', 2);
        $this->assertSame('confirmed', $this->ownItem->fresh()->restaurant_decision);
        $this->actingAs($this->owner)->getJson('/reservations/'.$this->reservation->id.'/pre-order/owner-view')->assertOk()->assertJsonPath('summary.grand_total', '51.00')->assertJsonPath('restaurant_review.totals.confirmed', '51.00');
        $this->correct()->assertConflict();
    }

    public function test_owner_cannot_correct_confirmed_unpublished_foreign_or_unavailable_items(): void
    {
        $this->correct()->assertConflict();
        $this->actingAs($this->staff);
        $this->decide($this->ownItem, 'confirmed');
        $this->decide($this->guestItem, 'rejected');
        $this->submit();
        $this->replacement->update(['is_available' => false]);
        $this->correct()->assertUnprocessable();
        $this->replacement->update(['is_available' => true]);
        $version = app(RestaurantPreOrderReviewService::class)->version($this->reservation->fresh());
        $this->actingAs($this->staff)->postJson('/reservations/'.$this->reservation->id.'/restaurant-review/items/'.$this->guestItem->id.'/replace', ['menu_item_id' => $this->replacement->id, 'quantity' => 1], ['If-Match' => $version])->assertForbidden();
        $this->actingAs($this->owner)->postJson('/reservations/'.$this->reservation->id.'/restaurant-review/items/'.$this->ownItem->id.'/replace', ['menu_item_id' => $this->replacement->id, 'quantity' => 1], ['If-Match' => $version])->assertConflict();
    }

    public function test_cutoff_blocks_customer_corrections_but_allows_staff_review(): void
    {
        $this->reservation->update(['reserved_at' => now()->addMinutes(30)]);
        $this->decide($this->ownItem, 'confirmed')->assertOk();
        $this->decide($this->guestItem, 'rejected')->assertOk();
        $this->submit()->assertOk();
        $this->correct()->assertConflict();
        $this->assertDatabaseCount('pre_order_items', 2);
    }

    public function test_cancellation_is_explicit_idempotent_scoped_and_preserves_all_order_records(): void
    {
        $before = PreOrderItem::orderBy('id')->get()->toJson();
        $version = $this->version();
        $this->postJson($this->root.'/cancel')->assertUnprocessable();
        $this->postJson($this->root.'/cancel', [], ['If-Match' => $version])->assertOk()->assertJsonPath('phase', 'canceled');
        $this->postJson($this->root.'/cancel', [], ['If-Match' => $version])->assertOk();
        $this->assertSame($before, PreOrderItem::orderBy('id')->get()->toJson());
        $this->decide($this->ownItem, 'confirmed')->assertConflict();
        $this->assertDatabaseCount('pre_orders', 1);
        $this->assertSame(1, DB::table('reservation_events')->where('kind', 'reservation_cancelled')->count());
    }

    public function test_dashboard_filters_and_table_edit_use_scoped_availability(): void
    {
        $date = $this->reservation->reserved_at->setTimezone('Asia/Tbilisi')->format('Y-m-d');
        $base = '/staff/restaurants/'.$this->restaurant->id.'/reservations';
        $this->get($base.'?date='.$date)->assertOk()->assertInertia(fn (Assert $page) => $page->component('staff/Reservations')->has('reservations.data', 1));
        $this->get($base.'?date='.$date.'&time=01:00')->assertInertia(fn (Assert $page) => $page->has('reservations.data', 0));
        $table = $this->restaurant->tables()->create(['name' => 'Table 2', 'seats' => 4]);
        $time = $this->reservation->reserved_at->setTimezone('Asia/Tbilisi')->format('H:i');
        $data = ['date' => $date, 'time' => $time, 'guest_count' => 4, 'table_id' => $table->id];
        $this->patchJson($this->root, $data, ['If-Match' => $this->version()])->assertOk()->assertJsonPath('table_id', $table->id);
        $this->assertDatabaseHas('pre_order_items', ['id' => $this->ownItem->id, 'unit_price' => '10.00']);
        $foreign = Restaurant::factory()->create(['name' => 'Restaurant', 'slug' => fake()->unique()->slug(), 'address' => 'Test address', 'is_active' => true])->tables()->create(['name' => 'Foreign', 'seats' => 4]);
        $this->patchJson($this->root, [...$data, 'table_id' => $foreign->id], ['If-Match' => $this->version()])->assertUnprocessable();
    }

    public function test_staff_cannot_review_drafts_or_edit_terminal_reservations(): void
    {
        $this->reservation->preOrder()->update(['status' => 'draft', 'submitted_at' => null]);
        $this->getJson($this->root.'/review')->assertConflict();
        $this->reservation->preOrder()->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->reservation->update(['status' => 'completed']);
        $this->getJson($this->root.'/review')->assertJsonPath('editable', false);
        $this->decide($this->ownItem, 'confirmed')->assertConflict();
        $this->postJson($this->root.'/cancel', [], ['If-Match' => $this->version()])->assertConflict();
    }

    public function test_new_reservation_reuses_booking_rules_and_never_creates_an_account(): void
    {
        $date = now()->addDays(5)->format('Y-m-d');
        $data = ['date' => $date, 'time' => '12:00', 'guest_count' => 2, 'table_id' => $this->reservation->restaurant_table_id, 'owner_email' => $this->owner->email];
        $base = '/staff/restaurants/'.$this->restaurant->id.'/reservations';
        $this->postJson($base, $data)->assertCreated()->assertJsonPath('guest_count', 2);
        $this->postJson($base, $data)->assertUnprocessable();
        $this->postJson($base, [...$data, 'owner_email' => 'missing@example.test'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('reservations', 2);
    }
}
