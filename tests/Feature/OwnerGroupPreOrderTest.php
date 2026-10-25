<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerGroupPreOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Reservation $reservation;

    private int $guestId;

    private int $ownItem;

    private int $guestItem;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Garden', 'slug' => 'garden', 'address' => 'Garden address']);
        $this->reservation = $restaurant->reservations()->create(['owner_user_id' => $this->owner->id, 'code' => 'GT-GROUP', 'status' => 'confirmed', 'reserved_at' => now()->addDay(), 'guest_count' => 4]);
        $own = $this->reservation->guests()->create(['user_id' => $this->owner->id, 'name' => $this->owner->name]);
        $guest = $this->reservation->guests()->create(['name' => 'Guest']);
        $this->guestId = $guest->id;
        $this->reservation->guests()->create(['name' => 'Empty guest']);
        $category = $restaurant->menuCategories()->create(['name' => 'Appetizers', 'slug' => 'appetizers']);
        $dish = $category->items()->create(['name' => 'Salad', 'price' => '999.00']);
        $order = $this->reservation->preOrder()->create(['status' => 'draft']);
        $this->ownItem = $order->items()->create(['reservation_guest_id' => $own->id, 'menu_item_id' => $dish->id, 'quantity' => 2, 'unit_price' => '17.00', 'notes' => 'No onions'])->id;
        $this->guestItem = $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => 1, 'unit_price' => '10.00'])->id;
        $this->url = '/reservations/'.$this->reservation->id.'/pre-order/owner-view';
        $this->actingAs($this->owner);
    }

    private function version(): string
    {
        return $this->getJson($this->url)->assertOk()->json('version');
    }

    private function change(int $item, int $quantity, ?string $version = null)
    {
        return $this->patchJson($this->url.'/items/'.$item, ['quantity' => $quantity], ['If-Match' => $version ?? $this->version()]);
    }

    public function test_view_keeps_owner_guest_empty_sections_notes_and_saved_totals_without_writes(): void
    {
        $before = PreOrderItem::orderBy('id')->get()->toJson();
        $result = $this->getJson($this->url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('summary.grand_total', '44.00')->assertJsonPath('participants.0.subtotal', '34.00')->assertJsonPath('participants.1.subtotal', '10.00')->assertJsonPath('participants.2.subtotal', '0.00')->assertJsonPath('participants.0.items.0.notes', 'No onions');
        $this->assertSame($result->json('version'), $result->headers->get('ETag'));
        $this->assertSame($before, PreOrderItem::orderBy('id')->get()->toJson());
    }

    public function test_omitted_malformed_and_stale_versions_cannot_overwrite_either_participant(): void
    {
        foreach ([null, '*', 'W/"'.str_repeat('a', 64).'"', '"0"'] as $version) {
            $this->patchJson($this->url.'/items/'.$this->ownItem, ['quantity' => 3], $version === null ? [] : ['If-Match' => $version])->assertUnprocessable();
        }
        $old = $this->version();
        $this->change($this->ownItem, 3, $old)->assertOk()->assertJsonPath('summary.grand_total', '61.00');
        $this->change($this->guestItem, 4, $old)->assertConflict();
        $this->change($this->guestItem, 2)->assertOk()->assertJsonPath('summary.grand_total', '71.00');
        $this->assertDatabaseHas('pre_order_items', ['id' => $this->ownItem, 'unit_price' => '17.00', 'notes' => 'No onions']);
    }

    public function test_quantity_removal_and_guest_removal_preserve_other_rows_and_booking(): void
    {
        $this->change($this->ownItem, 0)->assertOk()->assertJsonPath('summary.grand_total', '10.00');
        $this->deleteJson($this->url.'/guests/'.$this->guestId, [], ['If-Match' => $this->version()])->assertOk()->assertJsonPath('summary.grand_total', '0.00');
        $this->assertDatabaseCount('reservation_guests', 3);
        $this->assertDatabaseCount('reservations', 1);
        $guest = $this->reservation->guests()->findOrFail($this->guestId);
        $this->assertNotNull($guest->invitation_revoked_at);
        $this->assertDatabaseMissing('pre_order_items', ['id' => $this->guestItem]);
        $ownerGuest = $this->reservation->guests()->where('user_id', $this->owner->id)->sole();
        $this->deleteJson($this->url.'/guests/'.$ownerGuest->id, [], ['If-Match' => $this->version()])->assertUnprocessable();
    }

    public function test_confirmed_guest_items_are_immutable_and_cannot_be_removed_by_owner(): void
    {
        $this->reservation->guests()->whereKey($this->guestId)->update(['pre_order_submitted_at' => now()]);
        $this->getJson($this->url)->assertJsonPath('participants.1.editable', false)->assertJsonPath('participants.1.can_remove', false);
        $this->change($this->guestItem, 2)->assertConflict();
        $this->deleteJson($this->url.'/guests/'.$this->guestId, [], ['If-Match' => $this->version()])->assertConflict();
    }

    public function test_cross_owner_guest_and_cross_reservation_mutations_are_rejected(): void
    {
        $version = $this->version();
        $other = User::factory()->create();
        $this->reservation->guests()->create(['name' => 'Registered guest', 'user_id' => $other->id]);
        $this->actingAs($other)->getJson($this->url)->assertForbidden();
        $this->change($this->ownItem, 3, $version)->assertForbidden();
        $this->deleteJson($this->url.'/guests/'.$this->guestId, [], ['If-Match' => $version])->assertForbidden();
        $this->postJson($this->url.'/submit', [], ['If-Match' => $version])->assertForbidden();
        $this->actingAs($this->owner);
        $foreign = $this->reservation->replicate(['code']);
        $foreign->code = 'GT-OTHER';
        $foreign->save();
        $guest = $foreign->guests()->create(['name' => 'Other guest']);
        $this->deleteJson($this->url.'/guests/'.$guest->id, [], ['If-Match' => $this->version()])->assertNotFound();
        $this->change(999999, 2)->assertNotFound();
        $this->getJson('/reservations/999999/pre-order/owner-view')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url)->assertUnauthorized();
    }

    public function test_new_submission_is_versioned_and_repeated_success_is_idempotent(): void
    {
        $version = $this->version();
        $this->postJson($this->url.'/submit')->assertUnprocessable();
        $this->postJson($this->url.'/submit', [], ['If-Match' => $version])->assertOk()->assertJsonPath('status', 'submitted')->assertJsonPath('editable', false)->assertJsonPath('summary.grand_total', '44.00');
        $stamp = $this->reservation->preOrder->submitted_at->toIso8601String();
        $this->postJson($this->url.'/submit', [], ['If-Match' => $version])->assertOk()->assertJsonPath('submitted_at', $stamp);
        $this->change($this->ownItem, 3)->assertConflict();
    }

    public function test_availability_validation_notes_and_rejected_status_injection(): void
    {
        $item = PreOrderItem::findOrFail($this->ownItem);
        MenuItem::findOrFail($item->menu_item_id)->update(['is_available' => false]);
        $this->change($this->ownItem, 3)->assertUnprocessable();
        $this->patchJson($this->url.'/items/'.$this->ownItem, ['quantity' => 2, 'status' => 'rejected', 'unit_price' => '0.01'], ['If-Match' => $this->version()])->assertUnprocessable();
        $this->change($this->ownItem, 0)->assertOk();
    }

    public function test_terminal_unknown_states_and_start_cutoff_are_read_only_without_new_approval_rules(): void
    {
        foreach (['confirmed', 'rejected', 'cancelled'] as $status) {
            $this->reservation->preOrder()->update(['status' => $status]);
            $this->getJson($this->url)->assertOk()->assertJsonPath('status', $status)->assertJsonPath('editable', false);
            $this->change($this->ownItem, 3)->assertConflict();
        }
        $this->reservation->preOrder()->update(['status' => 'draft']);
        $this->reservation->update(['reserved_at' => now()->addMinutes(29)]);
        $this->getJson($this->url)->assertJsonPath('editable', true); // There is no existing 30-minute cutoff.
        $this->reservation->update(['reserved_at' => now()]);
        $this->change($this->ownItem, 3)->assertConflict();
        foreach (['completed', 'cancelled'] as $status) {
            $this->reservation->update(['status' => $status]);
            $this->change($this->ownItem, 3)->assertConflict();
        }
    }

    public function test_missing_owner_items_and_empty_orders_keep_exact_totals(): void
    {
        $this->change($this->ownItem, 0)->assertJsonPath('participants.0.items', [])->assertJsonPath('summary.grand_total', '10.00');
        $this->change($this->guestItem, 0)->assertJsonPath('summary.grand_total', '0.00')->assertJsonPath('can_submit', false);
        $this->postJson($this->url.'/submit', [], ['If-Match' => $this->version()])->assertUnprocessable();
    }

    public function test_mutations_require_session_csrf(): void
    {
        $this->app->instance('env', 'local');
        $this->withSession(['_token' => 'group-owner-csrf']);
        $page = $this->get('/reservations/'.$this->reservation->id)->assertOk();
        $xsrf = $page->getCookie('XSRF-TOKEN', false)->getValue();
        $version = $this->version();
        $this->change($this->ownItem,3,$version)->assertStatus(419);
        $this->patchJson($this->url.'/items/'.$this->ownItem,['quantity' => 3],['If-Match' => $version, 'X-XSRF-TOKEN' => $xsrf])->assertOk();
    }
}
