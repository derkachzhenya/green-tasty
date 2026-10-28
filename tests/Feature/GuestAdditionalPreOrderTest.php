<?php

namespace Tests\Feature;

use App\Models\GuestPreOrderRound;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use App\Services\GuestInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestAdditionalPreOrderTest extends TestCase
{
    use RefreshDatabase;

    private Reservation $reservation;

    private MenuItem $dish;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $restaurant = Restaurant::create(['name' => 'Green Tasty', 'slug' => 'additional', 'address' => '48 Rustaveli Avenue']);
        $this->reservation = $restaurant->reservations()->create(['owner_user_id' => User::factory()->create()->id, 'code' => 'ADDITIONAL', 'reserved_at' => now()->addDay(), 'guest_count' => 2, 'status' => 'confirmed']);
        $category = $restaurant->menuCategories()->create(['name' => 'Appetizers', 'slug' => 'appetizers']);
        $this->dish = $category->items()->create(['name' => 'Fresh Strawberry Mint Salad', 'price' => '17.00']);
        $issued = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Guest']);
        $this->token = basename(parse_url($issued['invitation_url'], PHP_URL_PATH));
        $this->postJson(route('invitations.identity', $this->token), ['name' => 'Alice'])->assertOk();
        $this->postJson(route('invitations.submit', $this->token), $this->items(), ['If-Match' => '"0"'])->assertOk();
    }

    private function items(int $quantity = 1): array
    {
        return ['items' => [['menu_item_id' => $this->dish->id, 'quantity' => $quantity]]];
    }

    private function start(int $version = 1)
    {
        return $this->postJson(route('invitations.start', $this->token), [], ['If-Match' => '"'.$version.'"']);
    }

    public function test_new_empty_cart_appends_a_separate_price_snapshot_and_preserves_previous_items(): void
    {
        $old = $this->reservation->preOrder()->sole()->items()->sole()->getRawOriginal();
        $this->dish->update(['price' => '19.00']);
        $this->start()->assertOk()->assertJsonPath('guest_state.draft_round', 1)->assertJsonPath('selections.items', [])->assertJsonPath('previous_selections.subtotal', '17.00');
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(2), ['If-Match' => '"2"'])->assertOk()->assertJsonPath('subtotal', '38.00');
        $this->assertSame($old, $this->reservation->preOrder()->sole()->items()->sole()->getRawOriginal());
        $this->assertSame('17.00', app(ConsolidatedPreOrderService::class)->summarize($this->reservation)['grand_total']);
        $this->postJson(route('invitations.submit', $this->token), $this->items(2), ['If-Match' => '"3"'])->assertOk()
            ->assertJsonPath('guest_state.draft_round', null)->assertJsonPath('guest_state.can_start_new', true)->assertJsonPath('selections.subtotal', '55.00');
        $order = $this->reservation->preOrder()->sole();
        $this->assertSame($old, $order->items()->whereKey($old['id'])->sole()->getRawOriginal());
        $this->assertSame(2, $order->items()->count());
        $this->assertSame('19.00', $order->items()->where('selection_round', 1)->sole()->unit_price);
        $this->assertSame('55.00', app(ConsolidatedPreOrderService::class)->summarize($this->reservation)['grand_total']);
        // Lost response / repeated submit must not append the same round twice.
        $this->postJson(route('invitations.submit', $this->token), $this->items(2), ['If-Match' => '"3"'])->assertOk()->assertJsonPath('selections.subtotal', '55.00');
        $this->assertSame(2, $order->items()->count());
        $this->start(3)->assertConflict();
        $this->start(4)->assertOk()->assertJsonPath('guest_state.draft_round', 2)->assertJsonPath('selections.items', [])->assertJsonPath('previous_selections.subtotal', '55.00');
    }

    public function test_retried_start_keeps_the_current_cart_and_stale_writes_cannot_change_it(): void
    {
        $this->start()->assertOk();
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(2), ['If-Match' => '"2"'])->assertOk();
        $this->start()->assertOk()->assertJsonPath('selections.subtotal', '34.00')->assertJsonPath('guest_state.version', 3);
        $this->assertDatabaseCount('guest_pre_order_rounds', 1);
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(99), ['If-Match' => '"2"'])->assertConflict();
        $this->postJson(route('invitations.submit', $this->token), $this->items(99), ['If-Match' => '"2"'])->assertConflict();
        $this->getJson(route('invitations.menu', $this->token))->assertOk()->assertJsonPath('selections.subtotal', '34.00');
        $this->assertSame('17.00', app(ConsolidatedPreOrderService::class)->summarize($this->reservation)['grand_total']);
    }

    #[DataProvider('closedStates')]
    public function test_closed_reservations_and_invalid_invitations_cannot_start_or_append(string $state): void
    {
        $this->start()->assertOk();
        match ($state) {
            'group' => $this->reservation->preOrder()->sole()->update(['status' => 'submitted', 'submitted_at' => now()]),
            'cancelled' => $this->reservation->update(['status' => 'cancelled']),
            'completed' => $this->reservation->update(['status' => 'completed']),
            'cutoff' => $this->reservation->update(['reserved_at' => now()->addMinutes(20)]),
            'revoked' => $this->reservation->guests()->sole()->update(['name' => 'Alice']),
            'expired' => $this->reservation->guests()->sole()->forceFill(['invitation_expires_at' => now()->subSecond()])->save(),
            'removed' => $this->reservation->guests()->sole()->forceFill(['removed_at' => now()])->save(),
        };
        if ($state === 'revoked') {
            app(GuestInvitationService::class)->revoke($this->reservation, $this->reservation->guests()->sole());
        }
        $status = in_array($state, ['revoked', 'expired', 'removed'], true) ? 404 : 409;
        $this->start(2)->assertStatus($status);
        $this->putJson(route('invitations.selections.update', $this->token), $this->items(2), ['If-Match' => '"2"'])->assertStatus($status);
        $this->postJson(route('invitations.submit', $this->token), $this->items(2), ['If-Match' => '"2"'])->assertStatus($status);
        $this->assertSame(1, $this->reservation->preOrder()->sole()->items()->count());
        $this->assertNull(GuestPreOrderRound::sole()->submitted_at);
    }

    public static function closedStates(): array
    {
        return [['group'], ['cancelled'], ['completed'], ['cutoff'], ['revoked'], ['expired'], ['removed']];
    }

    public function test_versions_empty_carts_unavailable_dishes_and_price_injection_are_rejected(): void
    {
        $this->postJson(route('invitations.start', $this->token))->assertUnprocessable();
        $this->start()->assertOk();
        $this->postJson(route('invitations.submit', $this->token), ['items' => []], ['If-Match' => '"2"'])->assertUnprocessable();
        $this->putJson(route('invitations.selections.update', $this->token), [...$this->items(), 'price' => '0.01'], ['If-Match' => '"2"'])->assertUnprocessable();
        $this->dish->update(['is_available' => false]);
        $this->postJson(route('invitations.submit', $this->token), $this->items(), ['If-Match' => '"2"'])->assertUnprocessable();
        $this->assertSame(1, $this->reservation->preOrder()->sole()->items()->count());
        $this->assertSame([], GuestPreOrderRound::sole()->items);
    }

    public function test_history_cannot_be_dropped_by_a_reverse_migration(): void
    {
        $this->start()->assertOk();
        $migration = require database_path('migrations/2026_10_27_220000_add_additional_guest_preorders.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Additional guest pre-orders exist.');
        $migration->down();
    }
}
