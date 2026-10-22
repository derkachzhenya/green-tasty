<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use App\Services\GuestDishSelectionService;
use App\Services\GuestInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestDishSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Reservation $reservation;

    private ReservationGuest $guest;

    private MenuCategory $category;

    private MenuItem $bowl;

    private MenuItem $salad;

    private string $token;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Green Tasty Test', 'slug' => 'test', 'address' => 'Test Address']);
        $this->reservation = $restaurant->reservations()->create([
            'owner_user_id' => $owner->id,
            'code' => 'GT-SELECTION-001',
            'reserved_at' => now()->addDay(),
            'guest_count' => 2,
        ]);
        $this->category = $restaurant->menuCategories()->create(['name' => 'Main Courses', 'slug' => 'main']);
        $this->bowl = $this->category->items()->create(['name' => 'Vegetable Bowl', 'price' => '12.50']);
        $this->salad = $this->category->items()->create(['name' => 'Garden Salad', 'price' => '8.50']);
        $invitation = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Alice']);
        $this->guest = ReservationGuest::findOrFail($invitation['guest_id']);
        $this->token = basename(parse_url($invitation['invitation_url'], PHP_URL_PATH));
        $this->url = route('invitations.selections.update', ['token' => $this->token]);
    }

    public function test_anonymous_guest_can_save_multiple_dishes_with_server_prices(): void
    {
        $response = $this->putJson($this->url, ['items' => [
            ['menu_item_id' => $this->salad->id, 'quantity' => 1],
            ['menu_item_id' => $this->bowl->id, 'quantity' => 2],
        ]])->assertOk()->assertExactJson([
            'items' => [
                ['menu_item_id' => $this->bowl->id, 'name' => 'Vegetable Bowl', 'quantity' => 2, 'unit_price' => '12.50', 'subtotal' => '25.00'],
                ['menu_item_id' => $this->salad->id, 'name' => 'Garden Salad', 'quantity' => 1, 'unit_price' => '8.50', 'subtotal' => '8.50'],
            ],
            'total_quantity' => 3,
            'subtotal' => '33.50',
        ]);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('pre_orders', 1);
        $this->assertDatabaseCount('pre_order_items', 2);
        $this->assertSame($this->guest->id, $this->item($this->bowl)->reservation_guest_id);
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertJsonMissingPath('guest_id')->assertDontSee($this->token)->assertDontSee($this->guest->invitation_token);
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['request']->hasSession());
    }

    public function test_guest_can_update_quantities_and_remove_omitted_dishes(): void
    {
        $this->putJson($this->url, ['items' => [
            ['menu_item_id' => $this->bowl->id, 'quantity' => 1],
            ['menu_item_id' => $this->salad->id, 'quantity' => 2],
        ]])->assertOk();
        $originalId = $this->item($this->bowl)->id;

        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 3]]])
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('subtotal', '37.50');

        $this->assertSame($originalId, $this->item($this->bowl)->id);
        $this->assertSame(3, $this->item($this->bowl)->quantity);
        $this->assertDatabaseCount('pre_order_items', 1);
    }

    public function test_empty_selections_do_not_create_an_order_when_none_exists(): void
    {
        $this->putJson($this->url, ['items' => []])->assertOk()->assertExactJson([
            'items' => [], 'total_quantity' => 0, 'subtotal' => '0.00',
        ]);

        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('pre_order_items', 0);
    }

    public function test_replacement_and_clearing_never_modify_another_guests_selections(): void
    {
        $other = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Bob Private']);
        $otherUrl = route('invitations.selections.update', ['token' => basename(parse_url($other['invitation_url'], PHP_URL_PATH))]);
        $this->putJson($otherUrl, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 5]]])->assertOk();
        $otherItem = PreOrderItem::where('reservation_guest_id', $other['guest_id'])->firstOrFail()->toArray();
        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->salad->id, 'quantity' => 2]]])
            ->assertOk()->assertDontSee('Bob Private')->assertJsonPath('total_quantity', 2);

        $this->putJson($this->url, ['items' => []])->assertOk()->assertExactJson([
            'items' => [], 'total_quantity' => 0, 'subtotal' => '0.00',
        ]);

        $this->assertSame($otherItem, PreOrderItem::where('reservation_guest_id', $other['guest_id'])->firstOrFail()->toArray());
        $this->assertDatabaseCount('pre_orders', 1);
        $this->assertDatabaseCount('pre_order_items', 1);
        $this->assertSame('draft', $this->reservation->preOrder()->firstOrFail()->status);
    }

    public function test_reordered_identical_retries_preserve_row_ids_prices_and_timestamps(): void
    {
        $items = [
            ['menu_item_id' => $this->bowl->id, 'quantity' => 2],
            ['menu_item_id' => $this->salad->id, 'quantity' => 1],
        ];
        $first = $this->putJson($this->url, ['items' => $items])->assertOk()->json();
        $before = $this->state();
        $orderBefore = $this->reservation->preOrder()->firstOrFail()->toArray();
        $this->bowl->update(['price' => '99.99']);
        $this->travel(2)->seconds();

        $this->putJson($this->url, ['items' => array_reverse($items)])->assertOk()->assertExactJson($first);

        $this->assertSame($before, $this->state());
        $this->assertSame($orderBefore, $this->reservation->preOrder()->firstOrFail()->toArray());
    }

    public function test_retained_dishes_keep_their_snapshot_but_readded_dishes_use_the_new_price(): void
    {
        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 1]]])->assertOk();
        $this->bowl->update(['price' => '20.00']);

        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 3]]])
            ->assertOk()->assertJsonPath('items.0.unit_price', '12.50')->assertJsonPath('subtotal', '37.50');
        $this->putJson($this->url, ['items' => []])->assertOk();
        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 3]]])
            ->assertOk()->assertJsonPath('items.0.unit_price', '20.00')->assertJsonPath('subtotal', '60.00');
    }

    public function test_guest_selections_integrate_with_consolidation_using_each_guests_historical_price(): void
    {
        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 2]]])->assertOk();
        $this->bowl->update(['price' => '14.00']);
        $other = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Bob']);
        $otherUrl = route('invitations.selections.update', ['token' => basename(parse_url($other['invitation_url'], PHP_URL_PATH))]);
        $this->putJson($otherUrl, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 3]]])->assertOk();

        $summary = app(ConsolidatedPreOrderService::class)->summarize($this->reservation);

        $this->assertSame([[
            'menu_item_id' => $this->bowl->id, 'name' => 'Vegetable Bowl', 'quantity' => 5, 'subtotal' => '67.00',
        ]], $summary['items']);
        $this->assertSame(['25.00', '42.00'], array_column($summary['guests'], 'subtotal'));
        $this->assertSame('67.00', $summary['grand_total']);
        $this->assertSame(5, $summary['total_quantity']);
        $this->assertDatabaseCount('pre_orders', 1);

        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 1]]])->assertOk();
        $this->assertSame('54.50', app(ConsolidatedPreOrderService::class)->summarize($this->reservation)['grand_total']);
        $this->putJson($this->url, ['items' => []])->assertOk();
        $summary = app(ConsolidatedPreOrderService::class)->summarize($this->reservation);
        $this->assertSame('42.00', $summary['grand_total']);
        $this->assertSame([$other['guest_id']], array_column($summary['guests'], 'reservation_guest_id'));
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantities_are_rejected_without_writes(mixed $quantity): void
    {
        $this->json('PUT', $this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => $quantity]]], [], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('pre_order_items', 0);
    }

    public static function invalidQuantities(): array
    {
        return [
            'zero' => [0], 'negative' => [-1], 'over maximum' => [100], 'fractional' => [1.5],
            'float integer' => [1.0], 'boolean' => [true], 'numeric string' => ['2'],
            'null' => [null], 'array' => [[]], 'oversized' => [PHP_INT_MAX],
        ];
    }

    #[DataProvider('invalidDishes')]
    public function test_invalid_dishes_are_rejected(string $kind): void
    {
        $id = $this->bowl->id;
        if ($kind === 'missing') {
            $id = 999999;
        } elseif ($kind === 'unavailable') {
            $this->bowl->update(['is_available' => false]);
        } elseif ($kind === 'inactive category') {
            $this->category->update(['is_active' => false]);
        } else {
            $restaurant = Restaurant::create(['name' => 'Other', 'slug' => 'other', 'address' => 'Other']);
            $category = $restaurant->menuCategories()->create(['name' => 'Other', 'slug' => 'other']);
            $id = $category->items()->create(['name' => 'Other dish', 'price' => '1.00'])->id;
        }

        $this->putJson($this->url, ['items' => [['menu_item_id' => $id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('pre_order_items', 0);
    }

    public static function invalidDishes(): array
    {
        return [['missing'], ['unavailable'], ['inactive category'], ['other restaurant']];
    }

    public function test_a_mixed_valid_and_invalid_replacement_leaves_the_previous_selection_unchanged(): void
    {
        $this->putJson($this->url, ['items' => [
            ['menu_item_id' => $this->bowl->id, 'quantity' => 1],
            ['menu_item_id' => $this->salad->id, 'quantity' => 2],
        ]])->assertOk();
        $before = $this->state();

        $this->putJson($this->url, ['items' => [
            ['menu_item_id' => $this->bowl->id, 'quantity' => 5],
            ['menu_item_id' => 999999, 'quantity' => 1],
        ]])->assertUnprocessable();

        $this->assertSame($before, $this->state());
    }

    public function test_duplicate_menu_item_ids_are_rejected(): void
    {
        $this->putJson($this->url, ['items' => [
            ['menu_item_id' => $this->bowl->id, 'quantity' => 1],
            ['menu_item_id' => $this->bowl->id, 'quantity' => 2],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.menu_item_id');

        $this->assertDatabaseCount('pre_orders', 0);
    }

    #[DataProvider('forbiddenRootFields')]
    public function test_client_supplied_prices_and_identities_are_never_accepted(string $field): void
    {
        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 1]], $field => 1])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('pre_orders', 0);
    }

    public static function forbiddenRootFields(): array
    {
        return array_map(fn (string $field): array => [$field], [
            'price', 'unit_price', 'guest_id', 'reservation_guest_id', 'reservation_id', 'pre_order_id', 'status', 'submitted_at',
        ]);
    }

    #[DataProvider('forbiddenLineFields')]
    public function test_extra_line_item_fields_are_rejected(string $field): void
    {
        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 1, $field => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0');

        $this->assertDatabaseCount('pre_orders', 0);
    }

    public static function forbiddenLineFields(): array
    {
        return [['price'], ['unit_price'], ['guest_id'], ['reservation_guest_id']];
    }

    public function test_items_are_required_and_must_be_a_bounded_list(): void
    {
        $this->putJson($this->url, [])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($this->url, ['items' => null])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($this->url, ['items' => ['custom' => ['menu_item_id' => $this->bowl->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($this->url, ['items' => array_fill(0, 101, ['menu_item_id' => $this->bowl->id, 'quantity' => 1])])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('pre_orders', 0);
    }

    #[DataProvider('invalidInvitationStates')]
    public function test_invalid_invitations_are_rejected_before_payload_validation(string $state): void
    {
        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 1]]])->assertOk();
        $before = $this->state();
        $url = $this->url;
        if ($state === 'malformed') {
            $url = route('invitations.selections.update', ['token' => 'invalid']);
        } elseif ($state === 'unknown') {
            $url = route('invitations.selections.update', ['token' => str_repeat('a', 64)]);
        } elseif ($state === 'stored hash') {
            $url = route('invitations.selections.update', ['token' => $this->guest->invitation_token]);
        } elseif ($state === 'expired') {
            $this->travel(8)->days();
        } elseif ($state === 'revoked') {
            app(GuestInvitationService::class)->revoke($this->reservation, $this->guest);
        } else {
            app(GuestInvitationService::class)->regenerate($this->reservation, $this->guest);
        }

        $this->putJson($url, [])->assertNotFound()->assertExactJson(['message' => 'Invitation not found.']);
        $this->assertSame($before, $this->state());
    }

    public static function invalidInvitationStates(): array
    {
        return [['malformed'], ['unknown'], ['stored hash'], ['expired'], ['revoked'], ['regenerated']];
    }

    public function test_a_laravel_login_does_not_replace_the_required_invitation(): void
    {
        $this->actingAs($this->reservation->owner)
            ->putJson(route('invitations.selections.update', ['token' => 'invalid']), ['items' => []])
            ->assertNotFound();

        $this->assertDatabaseCount('pre_orders', 0);
    }

    #[DataProvider('finalizedStates')]
    public function test_non_draft_or_submitted_orders_cannot_be_changed_or_cleared(string $status, bool $hasSubmittedAt): void
    {
        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 1]]])->assertOk();
        $order = $this->reservation->preOrder()->firstOrFail();
        $order->update(['status' => $status, 'submitted_at' => $hasSubmittedAt ? now() : null]);
        $before = $this->state();

        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->salad->id, 'quantity' => 2]]])->assertConflict();
        $this->putJson($this->url, ['items' => []])->assertConflict();
        $this->assertSame($before, $this->state());
    }

    public static function finalizedStates(): array
    {
        return [['submitted', true], ['submitted', false], ['draft', true], ['completed', false], ['cancelled', false]];
    }

    public function test_an_invitation_revoked_after_initial_resolution_cannot_write_selections(): void
    {
        $real = app(GuestInvitationService::class);
        $invitationService = Mockery::mock(GuestInvitationService::class);
        $invitationService->shouldReceive('resolve')->once()->with($this->token)->andReturn($real->resolve($this->token));
        $invitationService->shouldReceive('resolve')->once()->with($this->token, true)->andReturnUsing(function () use ($real) {
            $real->revoke($this->reservation, $this->guest);

            return $real->resolve($this->token, lockForUpdate: true);
        });

        $service = new GuestDishSelectionService($invitationService);

        $this->assertNull($service->replace($this->token, [['menu_item_id' => $this->bowl->id, 'quantity' => 1]]));
        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('pre_order_items', 0);
    }

    public function test_non_json_validation_failures_still_return_json_without_a_session(): void
    {
        $this->put($this->url, [])->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertFalse($this->app['request']->hasSession());
    }

    public function test_replacement_does_not_modify_rows_in_another_reservations_order(): void
    {
        $otherReservation = $this->reservation->restaurant->reservations()->create([
            'owner_user_id' => $this->reservation->owner_user_id,
            'code' => 'GT-SELECTION-OTHER',
            'reserved_at' => now()->addDays(2),
            'guest_count' => 1,
        ]);
        $otherGuest = $otherReservation->guests()->create(['name' => 'Other reservation guest']);
        $otherOrder = $otherReservation->preOrder()->create(['status' => 'draft']);
        $otherOrder->items()->create([
            'reservation_guest_id' => $otherGuest->id,
            'menu_item_id' => $this->bowl->id,
            'quantity' => 5,
            'unit_price' => '10.00',
        ]);
        // Even an inconsistent legacy row linking this guest to another order is out of scope.
        $otherOrder->items()->create([
            'reservation_guest_id' => $this->guest->id,
            'menu_item_id' => $this->salad->id,
            'quantity' => 3,
            'unit_price' => '7.00',
        ]);
        $before = $otherOrder->items()->orderBy('id')->get()->toArray();

        $this->putJson($this->url, ['items' => [['menu_item_id' => $this->bowl->id, 'quantity' => 2]]])->assertOk();
        $this->putJson($this->url, ['items' => []])->assertOk();

        $this->assertSame($before, $otherOrder->items()->orderBy('id')->get()->toArray());
    }

    public function test_writes_are_rate_limited_by_ip_even_with_invalid_tokens(): void
    {
        foreach (range(1, 30) as $index) {
            $this->putJson(route('invitations.selections.update', ['token' => 'invalid-'.$index]), ['items' => []])->assertNotFound();
        }

        $this->putJson($this->url, ['items' => []])->assertTooManyRequests()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('pre_orders', 0);
    }

    private function item(MenuItem $dish): PreOrderItem
    {
        return PreOrderItem::where('reservation_guest_id', $this->guest->id)->where('menu_item_id', $dish->id)->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function state(): array
    {
        return PreOrderItem::orderBy('id')->get()->values()->toArray();
    }
}
