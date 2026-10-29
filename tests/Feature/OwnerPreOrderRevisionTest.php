<?php

namespace Tests\Feature;

use App\Models\GuestPreOrderRound;
use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GroupPreOrderSubmissionService;
use App\Services\GuestDishSelectionService;
use App\Services\GuestInvitationService;
use App\Services\GuestPreOrderService;
use App\Services\MyReservationsService;
use App\Services\PreOrderTiming;
use App\Services\RestaurantPreOrderReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class OwnerPreOrderRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Reservation $reservation;

    private int $ownItem;

    private int $guestItem;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-01T10:00:00Z'));
        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Garden', 'slug' => 'garden', 'address' => 'Garden address']);
        $this->reservation = $restaurant->reservations()->create(['owner_user_id' => $this->owner->id, 'code' => 'GT-REVISIONS', 'reserved_at' => now()->addHour(), 'guest_count' => 4, 'status' => 'confirmed']);
        $owner = $this->reservation->guests()->create(['name' => 'Owner', 'user_id' => $this->owner->id]);
        $guest = $this->reservation->guests()->create(['name' => 'Alex']);
        $guest->forceFill(['pre_order_submitted_at' => now()])->save();
        $category = $restaurant->menuCategories()->create(['name' => 'Appetizers', 'slug' => 'appetizers']);
        $dish = $category->items()->create(['name' => 'Salad', 'price' => '99.00']);
        $order = $this->reservation->preOrder()->create(['status' => 'draft']);
        $this->ownItem = $order->items()->create(['reservation_guest_id' => $owner->id, 'menu_item_id' => $dish->id, 'quantity' => 1, 'unit_price' => '17.00', 'notes' => 'No onions'])->id;
        $this->guestItem = $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => 1, 'unit_price' => '10.00'])->id;
        $this->root = '/reservations/'.$this->reservation->id.'/pre-order/owner-view';
        $this->actingAs($this->owner);
    }

    private function version(): string
    {
        return $this->getJson($this->root)->assertOk()->json('version');
    }

    private function send(): void
    {
        app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner);
    }

    public function test_submitted_quantity_creates_revision_preserving_prices_notes_and_retry_safety(): void
    {
        $this->send();
        $stamp = $this->reservation->preOrder()->sole()->submitted_at->toIso8601String();
        $version = $this->version();
        $response = $this->patchJson($this->root.'/items/'.$this->ownItem, ['quantity' => 2], ['If-Match' => $version])->assertOk()->assertJsonPath('summary.grand_total', '44.00');
        $this->assertDatabaseHas('pre_order_items', ['id' => $this->ownItem, 'quantity' => 1, 'unit_price' => '17.00', 'notes' => 'No onions']);
        $this->assertNotNull(PreOrderItem::findOrFail($this->ownItem)->superseded_at);
        $new = collect($response->json('participants.0.items'))->sole();
        $this->assertNotSame($this->ownItem, $new['id']);
        $this->assertSame('pending', $new['decision']);
        $this->assertSame('No onions', $new['notes']);
        $this->assertDatabaseHas('pre_order_items', ['id' => $this->guestItem, 'quantity' => 1, 'unit_price' => '10.00', 'superseded_at' => null]);
        $this->assertDatabaseCount('pre_order_customer_revisions', 2);
        $this->assertSame($stamp, $this->reservation->preOrder()->sole()->submitted_at->toIso8601String());
        $this->patchJson($this->root.'/items/'.$this->ownItem, ['quantity' => 3], ['If-Match' => $version])->assertConflict();
        $this->patchJson($this->root.'/items/'.$new['id'], ['quantity' => 2], ['If-Match' => $response->json('version')])->assertOk();
        $this->assertDatabaseCount('pre_order_customer_revisions', 2);
        $this->assertDatabaseCount('pre_order_items', 3);
    }

    public function test_removing_confirmed_guest_from_sent_order_retains_history_and_reserved_seats(): void
    {
        $this->send();
        $guest = PreOrderItem::findOrFail($this->guestItem)->guest;
        $this->deleteJson($this->root.'/guests/'.$guest->id, [], ['If-Match' => $this->version()])->assertOk()->assertJsonPath('summary.grand_total', '17.00')->assertJsonPath('active_participants', 1);
        $this->assertNotNull($guest->fresh()->removed_at);
        $this->assertNotNull($guest->fresh()->invitation_revoked_at);
        $this->assertDatabaseHas('pre_order_items', ['id' => $this->guestItem, 'quantity' => 1, 'unit_price' => '10.00']);
        $this->assertNotNull(PreOrderItem::findOrFail($this->guestItem)->superseded_at);
        $this->assertSame(4, $this->reservation->fresh()->guest_count);
        $this->assertDatabaseCount('reservation_guests', 2);
        $this->assertSame(2, (int) $this->reservation->preOrder()->sole()->review_revision);
    }

    public function test_atomic_owner_cart_edit_after_submission_preserves_guest_and_old_rows(): void
    {
        $this->send();
        $this->get('/reservations/'.$this->reservation->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canEditOwnPreOrder', true));
        $url = '/reservations/'.$this->reservation->id.'/owner-pre-order';
        $menu = $this->getJson($url.'/menu')->assertOk()->assertJsonPath('order.editable', true)->json();
        $body = ['items' => [['menu_item_id' => $menu['selections']['items'][0]['menu_item_id'], 'quantity' => 3]]];
        $this->postJson($url.'/submit', $body, ['If-Match' => '"0"'])->assertOk()->assertJsonPath('selections.subtotal', '51.00');
        $this->assertDatabaseHas('pre_order_items', ['id' => $this->ownItem, 'quantity' => 1, 'unit_price' => '17.00']);
        $this->assertDatabaseHas('pre_order_items', ['id' => $this->guestItem, 'superseded_at' => null]);
        $this->getJson($url.'/menu')->assertJsonCount(1, 'selections.items')->assertJsonPath('selections.total_quantity', 3);
        $this->postJson($url.'/submit', $body, ['If-Match' => '"0"'])->assertOk();
        $this->assertDatabaseCount('pre_order_items', 3);
        $this->travelTo($this->reservation->reserved_at->toImmutable()->subMinutes(30));
        $this->get('/reservations/'.$this->reservation->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canEditOwnPreOrder', false));
        $this->postJson($url.'/submit', $body, ['If-Match' => '"0"'])->assertOk();
        $body['items'][0]['quantity'] = 4;
        $this->postJson($url.'/submit', $body, ['If-Match' => '"1"'])->assertConflict();
    }

    public function test_additional_saved_round_triggers_warning_when_original_selection_is_confirmed(): void
    {
        $r = $this->reservation;
        $r->preOrder->items()->delete();
        $invite = app(GuestInvitationService::class)->create($r, ['name' => 'Additional guest']);
        $token = basename($invite['invitation_url']);
        $guest = app(GuestInvitationService::class)->resolve($token);
        $guest->forceFill(['name_confirmed_at' => now()])->save();
        $dish = $r->restaurant->menuCategories()->first()->items()->first();
        $items = [['menu_item_id' => $dish->id, 'quantity' => 1]];
        app(GuestPreOrderService::class)->submit($token, $items, 0);
        $guest->refresh();
        app(GuestPreOrderService::class)->start($token, $guest->selection_version);
        app(GuestDishSelectionService::class)->replace($token, $items, $guest->fresh()->selection_version);
        $this->travelTo($r->reserved_at->toImmutable()->subMinutes(44));
        $this->assertNotNull($guest->fresh()->pre_order_submitted_at);
        $this->assertSame(1, GuestPreOrderRound::where('reservation_guest_id', $guest->id)->whereNull('submitted_at')->count());
        $this->assertTrue(PreOrderTiming::state($r)['unconfirmed']);
        $this->assertCount(1, app(MyReservationsService::class)->warnings($this->owner));
        $this->travelTo($r->reserved_at->toImmutable()->subMinutes(30));
        $this->assertSame([], app(MyReservationsService::class)->warnings($this->owner));
        $this->assertSame($items[0]['menu_item_id'], GuestPreOrderRound::where('reservation_guest_id', $guest->id)->sole()->items[0]['menu_item_id']);
    }

    public function test_guest_update_marker_is_persistent_authorized_and_cannot_acknowledge_a_racing_event(): void
    {
        $reviews = app(RestaurantPreOrderReviewService::class);
        $reviews->event($this->reservation, 'restaurant_review_submitted', 1, 'owner');
        $version = $this->version();
        $this->assertTrue(app(MyReservationsService::class)->forOwner($this->owner)['data'][0]['has_pre_order_updates']);
        $this->getJson($this->root)->assertOk();
        $this->assertTrue(app(MyReservationsService::class)->forOwner($this->owner)['data'][0]['has_pre_order_updates']);
        $reviews->event($this->reservation, 'items_rejected', 1, 'owner');
        $this->postJson($this->root.'/seen', [], ['If-Match' => $version])->assertConflict();
        $this->assertTrue(app(MyReservationsService::class)->forOwner($this->owner)['data'][0]['has_pre_order_updates']);
        $this->postJson($this->root.'/seen', [], ['If-Match' => $this->version()])->assertOk();
        $this->assertFalse(app(MyReservationsService::class)->forOwner($this->owner)['data'][0]['has_pre_order_updates']);
        $this->actingAs(User::factory()->create())->postJson($this->root.'/seen', [], ['If-Match' => $this->versionForOwner()])->assertForbidden();
    }

    private function versionForOwner(): string
    {
        return '"'.str_repeat('a', 64).'"';
    }

    public function test_sent_order_pending_review_and_rejection_warn_but_published_confirmation_does_not(): void
    {
        $this->send();
        $this->travelTo($this->reservation->reserved_at->toImmutable()->subMinutes(45));
        $this->assertTrue(PreOrderTiming::state($this->reservation)['unconfirmed']);
        $this->reservation->preOrder->items()->update(['restaurant_decision' => 'confirmed', 'review_published_at' => now()]);
        $this->assertFalse(PreOrderTiming::state($this->reservation)['unconfirmed']);
        PreOrderItem::whereKey($this->guestItem)->update(['restaurant_decision' => 'rejected']);
        $this->assertTrue(PreOrderTiming::state($this->reservation)['unconfirmed']);
        $this->travelTo($this->reservation->reserved_at->toImmutable()->subMinutes(30));
        $before = PreOrderItem::findOrFail($this->ownItem)->toJson();
        $this->patchJson($this->root.'/items/'.$this->ownItem, ['quantity' => 2], ['If-Match' => $this->version()])->assertConflict();
        $this->deleteJson($this->root.'/guests/'.PreOrderItem::findOrFail($this->guestItem)->reservation_guest_id, [], ['If-Match' => $this->version()])->assertConflict();
        $this->assertSame($before, PreOrderItem::findOrFail($this->ownItem)->toJson());
        $this->assertNotNull(PreOrderItem::findOrFail($this->guestItem)->cancelled_at);
        $this->assertDatabaseCount('pre_order_items', 2);
    }

    public function test_cancellation_from_card_keeps_all_records_and_closes_mutations(): void
    {
        $this->send();
        $row = app(MyReservationsService::class)->forOwner($this->owner)['data'][0];
        $url = '/reservations/'.$this->reservation->id.'/cancel';
        $items = PreOrderItem::orderBy('id')->get()->toJson();
        $this->assertTrue($row['can_cancel']);
        $this->postJson($url, [], ['If-Match' => $row['cancel_version']])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson($url, [], ['If-Match' => $row['cancel_version']])->assertOk();
        $this->assertSame($items, PreOrderItem::orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('reservation_guests', 2);
        $this->assertFalse(app(MyReservationsService::class)->forOwner($this->owner)['data'][0]['can_cancel']);
        $this->patchJson($this->root.'/items/'.$this->ownItem, ['quantity' => 2], ['If-Match' => $this->version()])->assertConflict();
    }

    public function test_restaurant_can_acknowledge_removal_of_all_previously_submitted_items(): void
    {
        $this->send();
        $staff = User::factory()->create();
        $restaurant = $this->reservation->restaurant;
        $staff->staffRestaurants()->attach($restaurant, ['role' => 'waiter', 'is_active' => true]);
        $review = app(RestaurantPreOrderReviewService::class);
        $oldVersion = $review->view($restaurant, $this->reservation, $staff)['version'];
        $this->patchJson($this->root.'/items/'.$this->ownItem, ['quantity' => 0], ['If-Match' => $this->version()])->assertOk();
        $guest = PreOrderItem::findOrFail($this->guestItem)->guest;
        $this->deleteJson($this->root.'/guests/'.$guest->id, [], ['If-Match' => $this->version()])->assertOk()->assertJsonPath('summary.grand_total', '0.00');
        $this->travelTo($this->reservation->reserved_at->toImmutable()->subMinutes(44));
        // There are no active dishes left to cancel or warn about.
        $this->assertFalse(PreOrderTiming::state($this->reservation)['unconfirmed']);
        try {
            $review->submit($restaurant, $this->reservation, $staff, $oldVersion);
            $this->fail('A stale review must not acknowledge a changed order.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $current = $review->view($restaurant, $this->reservation, $staff);
        $this->assertTrue($current['can_submit']);
        $published = $review->submit($restaurant, $this->reservation, $staff, $current['version']);
        $this->assertSame('Confirmed by Restaurant', $published['status']);
        $this->assertFalse(PreOrderTiming::state($this->reservation)['unconfirmed']);
        $this->assertDatabaseCount('pre_order_items', 2);
        $this->assertSame(2, $this->reservation->preOrder->items()->whereNotNull('superseded_at')->count());
        $this->assertDatabaseCount('pre_order_customer_revisions', 3);
    }

    public function test_cancellation_rejects_foreign_stale_payloads_and_exact_cutoff(): void
    {
        $row = app(MyReservationsService::class)->forOwner($this->owner)['data'][0];
        $url = '/reservations/'.$this->reservation->id.'/cancel';
        $this->actingAs(User::factory()->create())->postJson($url, [], ['If-Match' => $row['cancel_version']])->assertForbidden();
        $this->actingAs($this->owner)->postJson($url, ['status' => 'cancelled'], ['If-Match' => $row['cancel_version']])->assertUnprocessable();
        $this->postJson($url, [], ['If-Match' => '"'.str_repeat('0', 64).'"'])->assertConflict();
        $this->travelTo($this->reservation->reserved_at->toImmutable()->subMinutes(30));
        $this->postJson($url, [], ['If-Match' => $row['cancel_version']])->assertConflict();
        $this->assertSame('confirmed', $this->reservation->fresh()->status);
        $this->assertSame(0, DB::table('reservation_events')->where('kind', 'reservation_cancelled')->count());
    }
}
