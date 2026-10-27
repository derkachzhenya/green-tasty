<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GuestInvitationService;
use App\Services\MyReservationsService;
use App\Services\PreOrderTiming;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->getJson($this->group)->assertOk()->assertJsonPath('timing.locked', true)->assertJsonPath('editable', false)->assertJsonPath('summary.grand_total', '34.00');
        $this->getJson($this->own.'/menu')->assertOk()->assertJsonPath('order.editable', false)->assertJsonPath('context.date', '2026-11-01')->assertJsonPath('context.time', '00:15');
    }

    public function test_exact_cutoff_blocks_all_write_paths_without_cancelling_records_or_totals(): void
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
        $this->getJson('/invitations/'.$this->token.'/menu')->assertOk()->assertJsonPath('order.editable', false)->assertJsonPath('selections.subtotal', '17.00');
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

    public function test_owner_atomic_edit_is_versioned_and_cannot_touch_guest_or_submitted_group(): void
    {
        $this->postJson($this->own.'/submit', $this->items(), ['If-Match' => '"1"'])->assertOk();
        $this->postJson($this->own.'/submit', $this->items(3), ['If-Match' => '"0"'])->assertConflict();
        $this->postJson($this->own.'/submit', $this->items(2), ['If-Match' => '"1"'])->assertOk()->assertJsonPath('selections.subtotal', '34.00');
        $this->postJson($this->own.'/submit', $this->items(4), ['If-Match' => '"1"'])->assertConflict();
        $this->getJson('/invitations/'.$this->token.'/menu')->assertJsonPath('selections.subtotal', '17.00');
        $this->postJson('/reservations/'.$this->reservation->id.'/pre-order/submit')->assertOk();
        $this->postJson($this->own.'/submit', $this->items(3), ['If-Match' => '"2"'])->assertConflict();
        $this->postJson($this->own.'/submit', $this->items(2), ['If-Match' => '"1"'])->assertOk();
    }

    public function test_owner_can_save_empty_cart_without_creating_an_empty_confirmation(): void
    {
        $this->postJson($this->own.'/submit', ['items' => []], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('selections.subtotal', '0.00')->assertJsonPath('guest_state.submitted_at', null);
        $this->getJson($this->group)->assertJsonPath('summary.grand_total', '17.00');
    }

    public function test_only_real_unsubmitted_saved_items_trigger_warning_and_confirmed_group_does_not(): void
    {
        $this->travelTo($this->start->subMinutes(45));
        $this->reservation->guests()->update(['pre_order_submitted_at' => now()]);
        $this->assertFalse(PreOrderTiming::state($this->reservation)['unconfirmed']);
        $this->reservation->guests()->update(['pre_order_submitted_at' => null]);
        $this->reservation->preOrder()->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->assertFalse(PreOrderTiming::state($this->reservation)['unconfirmed']);
    }
}
