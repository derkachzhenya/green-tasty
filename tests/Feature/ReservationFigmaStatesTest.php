<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GuestInvitationService;
use App\Services\MyReservationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReservationFigmaStatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Reservation $reservation;

    private int $dishId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 25)->setTime(10, 0));
        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Garden', 'slug' => 'garden', 'address' => 'Garden address']);
        $this->reservation = $restaurant->reservations()->create(['owner_user_id' => $this->owner->id, 'reserved_at' => now()->addDay(), 'guest_count' => 4, 'status' => 'confirmed', 'code' => 'GT-STATES']);
        $this->reservation->guests()->create(['user_id' => $this->owner->id, 'name' => $this->owner->name]);
        $category = $restaurant->menuCategories()->create(['name' => 'Appetizers', 'slug' => 'appetizers']);
        $this->dishId = $category->items()->create(['name' => 'Fresh Strawberry Mint Salad', 'price' => '17.00'])->id;
        $this->actingAs($this->owner);
    }

    private function path(string $suffix): string
    {
        return '/reservations/'.$this->reservation->id.'/owner-pre-order'.$suffix;
    }

    private function items(int $quantity = 1): array
    {
        return ['items' => [['menu_item_id' => $this->dishId, 'quantity' => $quantity]]];
    }

    private function card(): array
    {
        return app(MyReservationsService::class)->forOwner($this->owner)['data'][0];
    }

    public function test_filters_and_global_counts_use_real_owned_states_and_time_boundaries(): void
    {
        foreach (['in_progress' => [now(), 'confirmed'], 'completed' => [now()->subHours(2), 'confirmed'], 'canceled' => [now()->addDay(), 'cancelled']] as $code => [$date, $status]) {
            $this->reservation->replicate(['code'])->fill(['code' => $code, 'reserved_at' => $date, 'status' => $status])->save();
        }
        $this->reservation->replicate(['code'])->fill(['code' => 'foreign', 'owner_user_id' => User::factory()->create()->id])->save();
        $this->assertSame(['all' => 4, 'upcoming' => 1, 'in_progress' => 1, 'completed' => 1, 'canceled' => 1], app(MyReservationsService::class)->counts($this->owner));
        foreach (['upcoming', 'in_progress', 'completed', 'canceled'] as $phase) {
            $result = app(MyReservationsService::class)->forOwner($this->owner, 1, $phase);
            $this->assertSame(1, $result['total']);
            $this->assertSame($phase, $result['data'][0]['phase']);
        }
        $this->getJson('/dashboard?status=unknown')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->get('/dashboard?status=completed')->assertInertia(fn (Assert $page) => $page->where('activeStatus', 'completed')->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservationCounts.all', 4)->where('reservations.total', 1)));
    }

    public function test_owner_cart_is_session_scoped_versioned_priced_and_idempotent(): void
    {
        $this->get($this->path(''))->assertOk()->assertDontSee('invitation_token');
        $this->getJson($this->path('/menu'))->assertOk()->assertJsonPath('guest_state.name_confirmed', true);
        $this->putJson($this->path('/selections'), $this->items())->assertUnprocessable();
        $this->putJson($this->path('/selections'), $this->items(2), ['If-Match' => '"0"'])->assertOk()->assertJsonPath('subtotal', '34.00');
        $this->assertSame(0, $this->card()['submitted_pre_orders']);
        $this->assertTrue($this->card()['can_add_pre_order']);
        $this->putJson($this->path('/selections'), $this->items(3), ['If-Match' => '"0"'])->assertConflict();
        $this->postJson($this->path('/submit'), $this->items(2), ['If-Match' => '"1"'])->assertOk()->assertJsonPath('selections.subtotal', '34.00');
        $before = $this->reservation->guests()->sole()->getRawOriginal();
        $this->postJson($this->path('/submit'), $this->items(99), ['If-Match' => '"0"'])->assertOk()->assertJsonPath('selections.total_quantity', 2);
        $this->assertSame($before, $this->reservation->guests()->sole()->getRawOriginal());
        $this->assertSame(1, $this->card()['submitted_pre_orders']);
        $this->assertFalse($this->card()['can_add_pre_order']);
        $this->assertTrue($this->card()['can_view_group']);
        $this->getJson('/reservations/'.$this->reservation->id.'/pre-order/summary')->assertOk()->assertJsonPath('grand_total', '34.00');
    }

    public function test_owner_edit_keeps_price_snapshot_and_never_overwrites_guest_items(): void
    {
        $issued = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Guest']);
        $token = basename($issued['invitation_url']);
        $this->postJson('/invitations/'.$token.'/identity', ['name' => 'Guest'])->assertOk();
        $this->postJson('/invitations/'.$token.'/submit', $this->items(), ['If-Match' => '"0"'])->assertOk();
        $this->assertSame(1, $this->card()['submitted_pre_orders']);
        $this->assertFalse($this->card()['can_view_group']);
        $this->postJson($this->path('/submit'), $this->items(), ['If-Match' => '"0"'])->assertOk();
        MenuItem::findOrFail($this->dishId)->update(['price' => '999.00']);
        $this->putJson($this->path('/selections'), $this->items(2), ['If-Match' => '"1"'])->assertOk()->assertJsonPath('subtotal', '34.00');
        $this->assertSame(1, $this->card()['submitted_pre_orders']);
        $this->postJson($this->path('/submit'), $this->items(2), ['If-Match' => '"2"'])->assertOk();
        $this->assertSame(2, $this->card()['submitted_pre_orders']);
        $this->getJson('/reservations/'.$this->reservation->id.'/pre-order/summary')->assertOk()->assertJsonPath('grand_total', '51.00');
        $this->postJson('/reservations/'.$this->reservation->id.'/pre-order/submit')->assertOk();
        $this->putJson($this->path('/selections'), $this->items(3), ['If-Match' => '"2"'])->assertConflict();
        $this->getJson('/invitations/'.$token.'/menu')->assertOk()->assertJsonPath('selections.subtotal', '17.00');
    }

    public function test_other_users_participants_and_bearers_cannot_manage_owner_cart_or_feedback(): void
    {
        $other = User::factory()->create();
        $this->reservation->guests()->create(['user_id' => $other->id, 'name' => 'Participant']);
        $this->actingAs($other);
        $this->get($this->path(''))->assertForbidden();
        $this->getJson($this->path('/menu'))->assertForbidden();
        $this->putJson($this->path('/selections'), $this->items(), ['If-Match' => '"0"'])->assertForbidden();
        $this->postJson($this->path('/submit'), $this->items(), ['If-Match' => '"0"'])->assertForbidden();
        $this->putJson('/reservations/'.$this->reservation->id.'/feedback', ['restaurant_rating' => 5, 'service_rating' => 5])->assertForbidden();
        $this->getJson('/reservations/999999/owner-pre-order/menu')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->path('/menu'))->assertUnauthorized();
    }

    public function test_closed_reservations_hide_invalid_actions_and_reject_owner_writes(): void
    {
        foreach (['cancelled', 'completed'] as $status) {
            $this->reservation->update(['status' => $status]);
            $card = $this->card();
            $this->assertFalse($card['can_invite']);
            $this->assertFalse($card['can_add_pre_order']);
            $this->assertFalse($card['can_view_group']);
            $this->getJson($this->path('/menu'))->assertOk()->assertJsonPath('order.editable', false);
            $this->putJson($this->path('/selections'), $this->items(), ['If-Match' => '"0"'])->assertConflict();
            $this->postJson($this->path('/submit'), $this->items(), ['If-Match' => '"0"'])->assertConflict();
        }
        $this->reservation->update(['status' => 'confirmed', 'reserved_at' => now()]);
        $this->assertFalse($this->card()['can_add_pre_order']);
        $this->putJson($this->path('/selections'), $this->items(), ['If-Match' => '"0"'])->assertConflict();
    }

    public function test_owner_mutations_require_csrf_and_reject_prices_and_foreign_menu_ids(): void
    {
        $this->app->instance('env', 'local');
        $this->withSession(['_token' => 'owner-cart-csrf']);
        $page = $this->get($this->path(''))->assertOk();
        $xsrf = $page->getCookie('XSRF-TOKEN', false)->getValue();
        $this->putJson($this->path('/selections'), $this->items(), ['If-Match' => '"0"'])->assertStatus(419);
        $this->putJson($this->path('/selections'), [...$this->items(), 'price' => '0.01'], ['If-Match' => '"0"', 'X-XSRF-TOKEN' => $xsrf])->assertUnprocessable();
        $other = Restaurant::create(['name' => 'Other', 'slug' => 'other', 'address' => 'Other address']);
        $category = $other->menuCategories()->create(['name' => 'Other', 'slug' => 'other']);
        $dish = $category->items()->create(['name' => 'Other dish', 'price' => '1.00']);
        $this->putJson($this->path('/selections'), ['items' => [['menu_item_id' => $dish->id, 'quantity' => 1]]], ['If-Match' => '"0"', 'X-XSRF-TOKEN' => $xsrf])->assertUnprocessable();
        $this->putJson($this->path('/selections'), $this->items(), ['If-Match' => '"0"', 'X-XSRF-TOKEN' => $xsrf])->assertOk();
    }

    public function test_all_submitted_progress_excludes_drafts_and_closes_invitation_actions(): void
    {
        $this->postJson($this->path('/submit'), $this->items(), ['If-Match' => '"0"'])->assertOk();
        for ($index = 0; $index < 3; $index++) {
            $issued = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Guest '.$index]);
            $token = basename($issued['invitation_url']);
            $this->postJson('/invitations/'.$token.'/identity', ['name' => 'Guest '.$index])->assertOk();
            $this->postJson('/invitations/'.$token.'/submit', $this->items(), ['If-Match' => '"0"'])->assertOk();
        }
        $this->assertSame(4, $this->card()['submitted_pre_orders']);
        $this->assertFalse($this->card()['can_invite']);
        $this->assertFalse($this->card()['can_add_pre_order']);
        $this->assertTrue($this->card()['can_view_group']);
        $this->postJson('/reservations/'.$this->reservation->id.'/pre-order/submit')->assertOk();
        $this->postJson('/reservations/'.$this->reservation->id.'/invitations', ['name' => 'Late guest'])->assertConflict();
        $this->get('/reservations/'.$this->reservation->id)->assertInertia(fn (Assert $page) => $page->where('canEditOwnPreOrder', false)->where('canManageInvitations', false));
        // Legacy finalized orders have no per-guest timestamp; their saved rows are still submitted.
        $this->reservation->guests()->update(['pre_order_submitted_at' => null]);
        $this->assertSame(4, $this->card()['submitted_pre_orders']);
    }

    public function test_closed_reservations_cannot_issue_or_regenerate_invitation_links(): void
    {
        $issued = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Guest']);
        foreach (['cancelled', 'completed'] as $status) {
            $this->reservation->update(['status' => $status]);
            $this->postJson('/reservations/'.$this->reservation->id.'/invitations', ['name' => 'Late guest'])->assertConflict();
            $this->postJson('/reservations/'.$this->reservation->id.'/invitations/'.$issued['guest_id'].'/regenerate')->assertConflict();
        }
        $this->assertDatabaseCount('reservation_guests', 2);
    }

    public function test_feedback_is_owner_only_eligible_persistent_and_updated_without_duplicates(): void
    {
        $url = '/reservations/'.$this->reservation->id.'/feedback';
        $this->putJson($url, ['restaurant_rating' => 5, 'service_rating' => 4])->assertConflict();
        $this->reservation->update(['reserved_at' => now()]);
        $this->assertSame('leave', $this->card()['feedback_action']);
        $this->putJson($url, ['restaurant_rating' => 6, 'service_rating' => 0])->assertUnprocessable();
        $this->putJson($url, ['restaurant_rating' => 5, 'service_rating' => 4, 'comment' => 'Fresh and friendly'])->assertOk();
        $this->assertSame('update', $this->card()['feedback_action']);
        $this->getJson($url)->assertOk()->assertJsonPath('feedback.service_rating', 4);
        $this->reservation->update(['status' => 'completed']);
        $this->putJson($url, ['restaurant_rating' => 4, 'service_rating' => 5])->assertOk();
        $this->assertDatabaseCount('reservation_feedback', 1);
        $this->reservation->update(['status' => 'cancelled']);
        $this->assertNull($this->card()['feedback_action']);
        $this->getJson($url)->assertConflict();
    }
}
