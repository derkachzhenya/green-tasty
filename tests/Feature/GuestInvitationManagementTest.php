<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GuestInvitationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00:00', 'UTC'));
        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Green Tasty', 'slug' => 'invitations-ui', 'address' => 'Tbilisi']);
        $this->reservation = $restaurant->reservations()->create([
            'owner_user_id' => $this->owner->id, 'code' => 'GT-INVITATIONS-UI', 'reserved_at' => now()->addDay(), 'guest_count' => 3,
        ]);
    }

    public function test_owner_dashboard_exposes_management_permission_and_expiration_but_never_an_issued_link_or_hash(): void
    {
        $this->actingAs($this->owner);
        $issued = $this->postJson($this->storeUrl(), ['name' => 'Alice'])->assertCreated()->json();
        $guest = ReservationGuest::query()->findOrFail($issued['guest_id']);
        $this->get(route('reservations.show', $this->reservation))->assertInertia(fn (Assert $page) => $page
            ->where('canManageInvitations', true)
            ->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('groupOrder.participants.0.name', 'Alice')
                ->where('groupOrder.participants.0.invitation_status', 'active')
                ->where('groupOrder.participants.0.invitation_expires_at', $issued['expires_at'])
                ->missing('groupOrder.participants.0.invitation_token')->missing('groupOrder.participants.0.invitation_url')));
        $this->get(route('reservations.show', $this->reservation), $this->headers())->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($guest->invitation_token)
            ->assertDontSee(basename($issued['invitation_url']))->assertDontSee($issued['invitation_url']);
    }

    public function test_regeneration_updates_dashboard_metadata_and_rejects_the_previous_link(): void
    {
        $this->actingAs($this->owner);
        $first = $this->postJson($this->storeUrl(), ['name' => 'Alice'])->assertCreated()->json();
        $this->travel(1)->hours();
        $issued = $this->postJson($this->guestUrl('regenerate', $first['guest_id']))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertNotSame($first['invitation_url'], $issued['invitation_url']);
        $this->assertSame($first['guest_id'], $issued['guest_id']);
        $this->getJson($first['invitation_url'])->assertNotFound();
        $this->getJson($issued['invitation_url'])->assertOk();
        $this->get(route('reservations.show', $this->reservation), $this->headers())->assertOk()
            ->assertJsonPath('props.groupOrder.participants.0.invitation_status', 'active')
            ->assertJsonPath('props.groupOrder.participants.0.invitation_expires_at', $issued['expires_at'])
            ->assertDontSee(basename($issued['invitation_url']))->assertDontSee(basename($first['invitation_url']));
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_revocation_updates_the_dashboard_and_preserves_the_guest_and_saved_dishes(): void
    {
        $this->actingAs($this->owner);
        $issued = $this->postJson($this->storeUrl(), ['name' => 'Alice'])->assertCreated()->json();
        $category = $this->reservation->restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $dish = $category->items()->create(['name' => 'Bowl', 'price' => '12.50']);
        $order = $this->reservation->preOrder()->create(['status' => 'draft']);
        $order->items()->create(['reservation_guest_id' => $issued['guest_id'], 'menu_item_id' => $dish->id, 'quantity' => 2, 'unit_price' => '11.00']);
        $this->deleteJson($this->guestUrl('destroy', $issued['guest_id']))->assertNoContent();
        $this->getJson($issued['invitation_url'])->assertNotFound();
        $this->get(route('reservations.show', $this->reservation), $this->headers())->assertOk()
            ->assertJsonPath('props.groupOrder.participants.0.invitation_status', 'revoked')
            ->assertJsonPath('props.groupOrder.participants.0.invitation_expires_at', $issued['expires_at'])
            ->assertJsonPath('props.groupOrder.summary.grand_total', '22.00');
        $this->assertDatabaseCount('reservation_guests', 1);
        $this->assertDatabaseCount('pre_order_items', 1);
    }

    public function test_expired_and_invalid_invitations_are_rejected_and_expiration_remains_visible(): void
    {
        $this->actingAs($this->owner);
        $issued = $this->postJson($this->storeUrl(), ['name' => 'Alice'])->assertCreated()->json();
        $this->travelTo(CarbonImmutable::parse($issued['expires_at']));
        $this->getJson($issued['invitation_url'])->assertNotFound();
        $this->getJson(route('invitations.show', str_repeat('a', 64)))->assertNotFound();
        $this->get(route('reservations.show', $this->reservation), $this->headers())->assertOk()
            ->assertJsonPath('props.groupOrder.participants.0.invitation_status', 'expired')
            ->assertJsonPath('props.groupOrder.participants.0.invitation_expires_at', $issued['expires_at']);
    }

    public function test_different_owners_and_authenticated_participants_cannot_use_the_management_apis(): void
    {
        $this->actingAs($this->owner);
        $issued = $this->postJson($this->storeUrl(), ['name' => 'Alice'])->assertCreated()->json();
        $participant = User::factory()->create();
        ReservationGuest::findOrFail($issued['guest_id'])->update(['user_id' => $participant->id]);
        foreach ([$participant, User::factory()->create()] as $user) {
            $this->actingAs($user)->getJson(route('reservations.show', $this->reservation))->assertForbidden();
            $this->postJson($this->storeUrl(), ['name' => 'Unauthorized'])->assertForbidden();
            $this->postJson($this->guestUrl('regenerate', $issued['guest_id']))->assertForbidden();
            $this->deleteJson($this->guestUrl('destroy', $issued['guest_id']))->assertForbidden();
        }
        $this->getJson($issued['invitation_url'])->assertOk();
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_owner_cannot_manage_a_guest_from_another_reservation(): void
    {
        $other = $this->reservation->restaurant->reservations()->create(['owner_user_id' => $this->owner->id, 'code' => 'GT-OTHER', 'reserved_at' => now()->addDay(), 'guest_count' => 1]);
        $guest = $other->guests()->create(['name' => 'Foreign']);
        $this->actingAs($this->owner)->postJson($this->guestUrl('regenerate', $guest->id))->assertNotFound();
        $this->deleteJson($this->guestUrl('destroy', $guest->id))->assertNotFound();
        $this->assertDatabaseHas('reservation_guests', ['id' => $guest->id, 'reservation_id' => $other->id]);
    }

    public function test_session_json_management_requests_require_the_normal_encrypted_xsrf_cookie_header(): void
    {
        $this->app->instance('env', 'local');
        $this->actingAs($this->owner)->withSession(['_token' => 'invitation-ui-csrf']);
        $page = $this->get(route('reservations.show', $this->reservation))->assertOk();
        $xsrf = $page->getCookie('XSRF-TOKEN', false)->getValue();
        $this->postJson($this->storeUrl(), ['name' => 'Alice'])->assertStatus(419);
        $issued = $this->postJson($this->storeUrl(), ['name' => 'Alice'], ['X-XSRF-TOKEN' => $xsrf])->assertCreated()->json();
        $this->postJson($this->guestUrl('regenerate', $issued['guest_id']), [], ['X-XSRF-TOKEN' => $xsrf])->assertOk();
        $this->deleteJson($this->guestUrl('destroy', $issued['guest_id']), [], ['X-XSRF-TOKEN' => $xsrf])->assertNoContent();
    }

    private function storeUrl(): string
    {
        return route('reservations.invitations.store', $this->reservation);
    }

    private function guestUrl(string $operation, int $guest): string
    {
        return route('reservations.invitations.'.$operation, [$this->reservation, $guest]);
    }

    private function headers(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->get(route('reservations.show', $this->reservation))->viewData('page')['version'], 'X-Inertia-Partial-Component' => 'reservations/Show', 'X-Inertia-Partial-Data' => 'groupOrder'];
    }
}
