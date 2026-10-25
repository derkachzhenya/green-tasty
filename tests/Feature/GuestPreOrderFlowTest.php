<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use App\Services\GroupPreOrderSubmissionService;
use App\Services\GuestInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestPreOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Reservation $reservation;

    private ReservationGuest $guest;

    private MenuItem $dish;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $restaurant = Restaurant::create(['name' => 'Green Tasty', 'slug' => 'guest-flow', 'address' => '48 Rustaveli Avenue']);
        $table = $restaurant->tables()->create(['name' => 'Window table', 'seats' => 4]);
        $this->reservation = Reservation::create(['owner_user_id' => User::factory()->create()->id, 'code' => 'GT-GUEST-FLOW', 'restaurant_id' => $restaurant->id, 'restaurant_table_id' => $table->id, 'guest_count' => 4, 'reserved_at' => '2026-11-01 08:00:00', 'status' => 'confirmed']);
        $category = $restaurant->menuCategories()->create(['name' => 'Appetizers', 'slug' => 'appetizers']);
        $this->dish = $category->items()->create(['name' => 'Fresh Strawberry Mint Salad', 'price' => '17.00']);
        $this->token = $this->invite('Invited guest');
        $this->guest = $this->reservation->guests()->sole();
    }

    private function invite(string $name): string
    {
        $issued = app(GuestInvitationService::class)->create($this->reservation, ['name' => $name]);

        return basename(parse_url($issued['invitation_url'], PHP_URL_PATH));
    }

    private function items(int $quantity = 1): array
    {
        return ['items' => [['menu_item_id' => $this->dish->id, 'quantity' => $quantity]]];
    }

    private function identify(): void
    {
        $this->postJson(route('invitations.identity', $this->token), ['name' => 'Alice'])->assertOk();
    }

    public function test_identity_reuses_the_existing_guest_and_exposes_only_permitted_reservation_facts(): void
    {
        $this->postJson(route('invitations.identity', $this->token), ['name' => '  Alice  ', 'reservation_id' => 999999, 'guest_id' => 999999])
            ->assertOk()->assertJsonPath('invitation.guest.name', 'Alice')->assertJsonPath('guest_state.name_confirmed', true)
            ->assertJsonPath('context.address', $this->reservation->restaurant->address)
            ->assertJsonPath('context.table', 'Window table')->assertJsonPath('context.time', '12:00')
            ->assertJsonPath('context.end_time', '14:00')->assertJsonPath('context.guests', 4)
            ->assertDontSee($this->token)->assertDontSee($this->guest->invitation_token)
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
        $before = $this->guest->fresh()->getRawOriginal();
        $this->identify();
        $this->assertSame($before, $this->guest->fresh()->getRawOriginal());
        $this->assertDatabaseCount('reservation_guests', 1);
        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertGuest();
        $this->assertFalse($this->app['request']->hasSession());
    }

    #[DataProvider('invalidNames')]
    public function test_guest_name_is_validated_before_changing_the_guest(mixed $name): void
    {
        $this->postJson(route('invitations.identity', $this->token), ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('Invited guest', $this->guest->fresh()->name);
        $this->assertNull($this->guest->fresh()->name_confirmed_at);
    }

    public static function invalidNames(): array
    {
        return [[''], ['   '], [null], [123], [['Alice']], [str_repeat('a', 256)], ["Alice\nBob"], ["Al\u{200B}ice"]];
    }

    public function test_guest_confirmation_preserves_saved_prices_and_does_not_submit_the_group_order(): void
    {
        $this->identify();
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(2), ['If-Match' => '"0"'])->assertOk()->assertJsonPath('subtotal', '34.00');
        $this->dish->update(['price' => '999.00']);
        $response = $this->postJson(route('invitations.submit', $this->token), $this->items(3), ['If-Match' => '"1"'])
            ->assertOk()->assertJsonPath('selections.subtotal', '51.00')->assertJsonPath('selections.items.0.unit_price', '17.00')
            ->assertJsonPath('guest_state.version', 2)->assertJsonPath('order.status', 'draft')->assertJsonPath('order.editable', false);
        $this->assertNotNull($response->json('guest_state.submitted_at'));
        $this->assertNull($this->reservation->preOrder()->sole()->submitted_at);
        $this->assertSame($this->guest->id, $this->reservation->preOrder()->sole()->items()->sole()->reservation_guest_id);
        $before = $this->reservation->preOrder()->sole()->items()->sole()->getRawOriginal();
        $submittedAt = $this->guest->fresh()->pre_order_submitted_at;
        $this->postJson(route('invitations.submit', $this->token), $this->items(99), ['If-Match' => '"0"'])->assertOk()->assertJsonPath('selections.subtotal', '51.00');
        $this->assertEquals($submittedAt, $this->guest->fresh()->pre_order_submitted_at);
        $this->assertSame($before, $this->reservation->preOrder()->sole()->items()->sole()->getRawOriginal());
        $this->putJson(route('invitations.selections.update', $this->token), ['items' => []], ['If-Match' => '"2"'])->assertConflict();
        $this->postJson(route('invitations.identity', $this->token), ['name' => 'Changed'])->assertConflict();
        $this->getJson(route('invitations.menu', $this->token))->assertOk()->assertJsonPath('selections.subtotal', '51.00');
    }

    public function test_multiple_guests_confirm_independently_and_owner_retains_group_submission_authority(): void
    {
        $bob = $this->invite('Bob');
        $this->identify();
        $this->postJson(route('invitations.submit', $this->token), $this->items(2), ['If-Match' => '"0"'])->assertOk();
        $this->postJson(route('invitations.identity', $bob), ['name' => 'Bob'])->assertOk();
        $this->postJson(route('invitations.submit', $bob), $this->items(3), ['If-Match' => '"0"'])->assertOk()->assertJsonPath('selections.total_quantity', 3)->assertDontSee('Alice');
        $summary = app(ConsolidatedPreOrderService::class)->summarize($this->reservation);
        $this->assertCount(2, $summary['guests']);
        $this->assertSame('85.00', $summary['grand_total']);
        $this->postJson(route('reservations.pre-order.submit', $this->reservation))->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson(route('reservations.pre-order.submit', $this->reservation))->assertForbidden();
        $this->actingAs($this->reservation->owner)->postJson(route('reservations.pre-order.submit', $this->reservation))->assertOk()->assertJsonPath('grand_total', '85.00');
        $this->assertSame('submitted', $this->reservation->preOrder()->sole()->status);
        $this->postJson(route('invitations.submit', $this->token), $this->items(), ['If-Match' => '"0"'])->assertOk()->assertJsonPath('selections.total_quantity', 2);
        $this->assertDatabaseCount('pre_orders', 1);
        $this->assertDatabaseCount('pre_order_items', 2);
    }

    public function test_stale_tab_cannot_overwrite_or_confirm_a_newer_cart(): void
    {
        $this->identify();
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(2), ['If-Match' => '"0"'])->assertOk();
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(3), ['If-Match' => '"0"'])->assertConflict();
        $this->postJson(route('invitations.submit', $this->token), $this->items(3), ['If-Match' => '"0"'])->assertConflict();
        $this->getJson(route('invitations.menu', $this->token))->assertOk()->assertJsonPath('guest_state.version', 1)->assertJsonPath('selections.total_quantity', 2);
        $this->assertNull($this->guest->fresh()->pre_order_submitted_at);
    }

    public function test_omitting_the_version_cannot_bypass_a_newer_cart(): void
    {
        $this->identify();
        $url = route('invitations.selections.update', $this->token);
        $this->putJson($url, $this->items(2), ['If-Match' => '"0"'])->assertOk();
        $this->putJson($url, $this->items(99))->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->getJson(route('invitations.menu', $this->token))->assertOk()
            ->assertJsonPath('guest_state.version', 1)->assertJsonPath('selections.total_quantity', 2);
    }

    #[DataProvider('invalidVersionHeaders')]
    public function test_unusable_versions_cannot_change_clear_or_submit_a_cart(?string $version): void
    {
        $this->identify();
        $url = route('invitations.selections.update', $this->token);
        $this->putJson($url, $this->items(2), ['If-Match' => '"0"'])->assertOk();
        $headers = $version === null ? [] : ['If-Match' => $version];
        $before = $this->reservation->preOrder()->sole()->items()->sole()->getRawOriginal();
        $this->putJson($url, $this->items(99), $headers)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson($url, ['items' => []], $headers)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson(route('invitations.submit', $this->token), $this->items(99), $headers)->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame($before, $this->reservation->preOrder()->sole()->items()->sole()->getRawOriginal());
        $this->assertSame(1, $this->guest->fresh()->selection_version);
        $this->assertNull($this->guest->fresh()->pre_order_submitted_at);
    }

    public static function invalidVersionHeaders(): array
    {
        return [[null], [''], ['*'], ['1'], ['W/"1"'], ['"01"'], ['"-1"'], ['"1.0"'], ['"1", "2"']];
    }

    public function test_a_token_and_version_only_authorize_their_own_guest_and_reservation(): void
    {
        $this->identify();
        $this->postJson(route('invitations.submit', $this->token), $this->items(2), ['If-Match' => '"0"'])->assertOk();
        $original = $this->guest->preOrderItems()->sole()->getRawOriginal();
        $bob = $this->invite('Bob');
        $foreign = Reservation::create(['restaurant_id' => $this->reservation->restaurant_id,
            'owner_user_id' => $this->reservation->owner_user_id, 'code' => 'FOREIGN-GUEST-SCOPE',
            'reserved_at' => now()->addDays(2), 'guest_count' => 1]);
        $foreignGuest = $foreign->guests()->create(['name' => 'Foreign guest']);
        $foreignOrder = $foreign->preOrder()->create(['status' => 'draft']);
        $foreignOrder->items()->create(['reservation_guest_id' => $foreignGuest->id, 'menu_item_id' => $this->dish->id, 'quantity' => 8, 'unit_price' => '0.10']);
        $foreignBefore = $foreignOrder->items()->sole()->getRawOriginal();
        $url = route('invitations.selections.update', $bob).'?guest_id='.$this->guest->id.'&reservation_id='.$foreign->id;
        $this->putJson($url, $this->items(3), ['If-Match' => '"0"'])->assertUnprocessable();
        $this->putJson(route('invitations.selections.update', $bob), $this->items(3), ['If-Match' => '"0"'])->assertOk()->assertJsonPath('subtotal', '51.00');
        $this->putJson(route('invitations.selections.update', $bob), [...$this->items(99), 'reservation_guest_id' => $this->guest->id], ['If-Match' => '"1"'])->assertUnprocessable();
        $this->getJson(route('invitations.menu', $bob).'?guest_id='.$this->guest->id.'&reservation_id='.$foreign->id)
            ->assertOk()->assertJsonPath('selections.total_quantity', 3)->assertJsonPath('guest_state.version', 1)->assertDontSee('Alice')->assertDontSee('Foreign guest');
        $this->assertSame($original, $this->guest->preOrderItems()->sole()->getRawOriginal());
        $this->assertSame($foreignBefore, $foreignOrder->items()->sole()->getRawOriginal());
    }

    public function test_consolidation_retains_each_submitted_guests_saved_price_for_the_same_dish(): void
    {
        $this->identify();
        $this->postJson(route('invitations.submit', $this->token), $this->items(3), ['If-Match' => '"0"'])->assertOk();
        $this->dish->update(['price' => '0.10']);
        $bob = $this->invite('Bob');
        $this->postJson(route('invitations.identity', $bob), ['name' => 'Bob'])->assertOk();
        $this->postJson(route('invitations.submit', $bob), $this->items(2), ['If-Match' => '"0"'])->assertOk()->assertJsonPath('selections.subtotal', '0.20');
        $this->actingAs($this->reservation->owner)->getJson(route('reservations.pre-order.summary', $this->reservation))
            ->assertOk()->assertJsonPath('grand_total', '51.20')->assertJsonPath('total_quantity', 5)
            ->assertJsonPath('items.0.quantity', 5)->assertJsonPath('guests.0.subtotal', '51.00')->assertJsonPath('guests.1.subtotal', '0.20');
        $this->actingAs($this->reservation->owner)->postJson(route('reservations.pre-order.submit', $this->reservation))
            ->assertOk()->assertJsonPath('grand_total', '51.20')->assertJsonPath('guests.0.items.0.unit_price', '17.00')->assertJsonPath('guests.1.items.0.unit_price', '0.10');
    }

    public function test_identical_cart_retry_does_not_advance_the_version(): void
    {
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(), ['If-Match' => '"0"'])->assertOk();
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(), ['If-Match' => '"1"'])->assertOk();
        $this->assertSame(1, $this->guest->fresh()->selection_version);
        $this->putJson(route('invitations.selections.update', $this->token), ['items' => []], ['If-Match' => '"1"'])->assertOk();
        $this->assertSame(2, $this->guest->fresh()->selection_version);
    }

    public function test_submission_requires_a_name_nonempty_cart_and_valid_version(): void
    {
        $url = route('invitations.submit', $this->token);
        $this->postJson($url, $this->items(), ['If-Match' => '"0"'])->assertConflict();
        $this->identify();
        $this->postJson($url, ['items' => []], ['If-Match' => '"0"'])->assertUnprocessable();
        $this->postJson($url, $this->items())->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson($url, $this->items(), ['If-Match' => 'invalid'])->assertUnprocessable();
        $this->postJson($url, [...$this->items(), 'total' => '0.01', 'reservation_id' => 999])->assertUnprocessable();
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_unavailable_and_foreign_dishes_are_rejected_without_partial_submission(): void
    {
        $this->identify();
        $this->dish->update(['is_available' => false]);
        $this->postJson(route('invitations.submit', $this->token), $this->items(), ['If-Match' => '"0"'])->assertUnprocessable();
        $foreignRestaurant = Restaurant::create(['name' => 'Foreign restaurant', 'slug' => 'foreign', 'address' => 'Other address']);
        $foreign = $foreignRestaurant->menuCategories()->create(['name' => 'Foreign', 'slug' => 'foreign'])->items()->create(['name' => 'Foreign dish', 'price' => '1.00']);
        $this->postJson(route('invitations.submit', $this->token), ['items' => [['menu_item_id' => $foreign->id, 'quantity' => 1]]], ['If-Match' => '"0"'])->assertUnprocessable();
        $this->assertNull($this->guest->fresh()->pre_order_submitted_at);
        $this->assertDatabaseCount('pre_orders', 0);
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_links_cannot_identify_or_submit_even_when_logged_in(string $state): void
    {
        $token = $this->token;
        match ($state) {
            'expired' => $this->guest->forceFill(['invitation_expires_at' => now()->subSecond()])->save(),
            'revoked' => app(GuestInvitationService::class)->revoke($this->reservation, $this->guest),
            'rotated' => app(GuestInvitationService::class)->regenerate($this->reservation, $this->guest),
            'hash' => $token = $this->guest->invitation_token,
            default => $token = str_repeat('f', 64),
        };
        $this->actingAs($this->reservation->owner);
        $this->postJson(route('invitations.identity', $token), ['name' => 'Attacker'])->assertNotFound();
        $this->postJson(route('invitations.submit', $token), $this->items(), ['If-Match' => '"0"'])->assertNotFound();
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public static function invalidLinks(): array
    {
        return [['expired'], ['revoked'], ['rotated'], ['hash'], ['unknown']];
    }

    #[DataProvider('closedReservations')]
    public function test_closed_reservations_do_not_accept_guest_changes(string $status): void
    {
        $this->identify();
        $this->reservation->update(['status' => $status]);
        $this->postJson(route('invitations.submit', $this->token), $this->items(), ['If-Match' => '"0"'])->assertConflict();
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(), ['If-Match' => '"0"'])->assertConflict();
        $this->getJson(route('invitations.menu', $this->token))->assertOk()->assertJsonPath('order.editable', false);
    }

    public static function closedReservations(): array
    {
        return [['cancelled'], ['completed']];
    }

    public function test_owner_group_submission_blocks_unconfirmed_guest_submission(): void
    {
        $this->identify();
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(), ['If-Match' => '"0"'])->assertOk();
        app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->reservation->owner);
        $this->postJson(route('invitations.submit', $this->token), $this->items(), ['If-Match' => '"1"'])->assertConflict();
        $this->assertNull($this->guest->fresh()->pre_order_submitted_at);
    }
}
