<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\PreOrder;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use App\Services\GroupPreOrderSubmissionService;
use App\Services\GuestInvitationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GroupPreOrderSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Reservation $reservation;

    private PreOrder $preOrder;

    private MenuItem $dish;

    private ReservationGuest $alice;

    private string $guestToken;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Green Tasty', 'slug' => 'test', 'address' => 'Test Address']);
        $this->reservation = $restaurant->reservations()->create([
            'owner_user_id' => $this->owner->id, 'code' => 'GT-SUBMIT',
            'reserved_at' => now()->addDay(), 'guest_count' => 2,
        ]);
        $category = $restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $this->dish = $category->items()->create(['name' => 'Vegetable Bowl', 'price' => '99.99']);
        $invitation = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Alice']);
        $this->alice = ReservationGuest::findOrFail($invitation['guest_id']);
        $this->guestToken = basename(parse_url($invitation['invitation_url'], PHP_URL_PATH));
        $bob = $this->reservation->guests()->create(['name' => 'Bob']);
        $this->preOrder = $this->reservation->preOrder()->create(['status' => 'draft']);
        foreach ([[$this->alice, 2, '12.50'], [$bob, 1, '10.00']] as [$guest, $quantity, $price]) {
            $this->preOrder->items()->create([
                'reservation_guest_id' => $guest->id, 'menu_item_id' => $this->dish->id,
                'quantity' => $quantity, 'unit_price' => $price,
            ]);
        }
        $this->url = route('reservations.pre-order.submit', $this->reservation);
    }

    public function test_owner_submits_the_order_and_receives_the_consolidated_historical_summary(): void
    {
        $before = $this->preOrder->items()->orderBy('id')->get()->toArray();
        $expected = app(ConsolidatedPreOrderService::class)->summarize($this->reservation);
        $this->travelTo(now()->startOfSecond());

        $this->actingAs($this->owner)->postJson($this->url)->assertOk()->assertExactJson([
            ...$expected, 'status' => 'submitted', 'submitted_at' => now()->toIso8601String(),
        ])->assertHeader('Cache-Control', 'no-store, private');

        $this->preOrder->refresh();
        $this->assertSame('submitted', $this->preOrder->status);
        $this->assertTrue($this->preOrder->submitted_at->equalTo(now()));
        $this->assertSame($before, $this->preOrder->items()->orderBy('id')->get()->toArray());
        $this->assertSame('draft', $this->reservation->fresh()->status);
        $this->assertDatabaseCount('pre_orders', 1);
    }

    public function test_repeated_submissions_preserve_the_original_timestamp_and_prices(): void
    {
        $first = $this->actingAs($this->owner)->postJson($this->url)->assertOk()->json();
        $orderBefore = $this->preOrder->fresh()->getRawOriginal();
        $itemsBefore = $this->preOrder->items()->orderBy('id')->get()->toArray();
        $this->dish->update(['price' => '150.00']);
        $this->travel(1)->hours();

        $this->postJson($this->url)->assertOk()->assertExactJson($first);

        $this->assertSame($orderBefore, $this->preOrder->fresh()->getRawOriginal());
        $this->assertSame($itemsBefore, $this->preOrder->items()->orderBy('id')->get()->toArray());
    }

    public function test_another_authenticated_user_cannot_submit_or_expose_the_summary(): void
    {
        $this->mock(GroupPreOrderSubmissionService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('submit');
        });

        $this->actingAs(User::factory()->create())->postJson($this->url, ['grand_total' => '0.01'])
            ->assertForbidden()->assertJsonMissingPath('guests')->assertDontSee('Alice');
        $this->assertDraft();
    }

    public function test_an_authenticated_participant_is_not_allowed_to_submit(): void
    {
        $participant = User::factory()->create();
        $this->alice->update(['user_id' => $participant->id]);

        $this->actingAs($participant)->postJson($this->url)->assertForbidden();
        $this->assertDraft();
    }

    public function test_an_invitation_token_cannot_replace_owner_authentication(): void
    {
        $this->postJson($this->url.'?token='.$this->guestToken)->assertUnauthorized();
        $this->get($this->url)->assertMethodNotAllowed();
        $this->assertDraft();
    }

    public function test_an_unauthenticated_browser_request_redirects_to_login(): void
    {
        $this->post($this->url)->assertRedirect(route('login'));
        $this->assertDraft();
    }

    public function test_a_nonexistent_reservation_returns_404_to_an_authenticated_owner(): void
    {
        $this->actingAs($this->owner)->postJson(route('reservations.pre-order.submit', $this->reservation->id + 1))->assertNotFound();
        $this->assertDraft();
    }

    public function test_a_missing_pre_order_is_rejected_without_creating_one(): void
    {
        $this->preOrder->items()->delete();
        $this->preOrder->delete();

        $this->actingAs($this->owner)->postJson($this->url)->assertUnprocessable()->assertJsonValidationErrors('pre_order');
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_an_existing_empty_order_is_not_submitted(): void
    {
        $this->preOrder->items()->delete();

        $this->actingAs($this->owner)->postJson($this->url)->assertUnprocessable()->assertJsonValidationErrors('pre_order');
        $this->assertDraft();
    }

    public function test_a_nonempty_order_with_free_dishes_is_valid(): void
    {
        $this->preOrder->items()->update(['unit_price' => '0.00']);

        $this->actingAs($this->owner)->postJson($this->url)->assertOk()
            ->assertJsonPath('grand_total', '0.00')->assertJsonPath('total_quantity', 3)->assertJsonPath('status', 'submitted');
    }

    #[DataProvider('forbiddenInput')]
    public function test_submission_never_accepts_client_order_data(string $field): void
    {
        $this->actingAs($this->owner)->postJson($this->url, [$field => 1])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDraft();
    }

    public static function forbiddenInput(): array
    {
        return array_map(fn (string $field): array => [$field], [
            'grand_total', 'total_quantity', 'price', 'unit_price', 'owner_user_id', 'user_id',
            'reservation_id', 'pre_order_id', 'guest_id', 'items', 'status', 'submitted_at',
        ]);
    }

    #[DataProvider('inconsistentOrderStates')]
    public function test_incompatible_or_inconsistent_pre_order_states_are_rejected(string $status, bool $timestamp): void
    {
        $this->preOrder->update(['status' => $status, 'submitted_at' => $timestamp ? now() : null]);
        $before = $this->preOrder->fresh()->getRawOriginal();

        $this->actingAs($this->owner)->postJson($this->url)->assertConflict();
        $this->assertSame($before, $this->preOrder->fresh()->getRawOriginal());
    }

    public static function inconsistentOrderStates(): array
    {
        return [['draft', true], ['submitted', false], ['cancelled', false], ['completed', true]];
    }

    #[DataProvider('terminalReservations')]
    public function test_terminal_reservations_cannot_submit_a_draft_order(string $status): void
    {
        $this->reservation->update(['status' => $status]);

        $this->actingAs($this->owner)->postJson($this->url)->assertConflict();
        $this->assertDraft();
    }

    public static function terminalReservations(): array
    {
        return [['cancelled'], ['completed']];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_legacy_selections_cannot_be_finalized(string $kind): void
    {
        $item = $this->preOrder->items()->firstOrFail();
        if ($kind === 'zero quantity') {
            $item->update(['quantity' => 0]);
        } elseif ($kind === 'negative price') {
            $item->update(['unit_price' => '-1.00']);
        } elseif ($kind === 'foreign guest') {
            $otherReservation = $this->reservation->restaurant->reservations()->create([
                'owner_user_id' => $this->owner->id, 'code' => 'OTHER', 'reserved_at' => now()->addDay(), 'guest_count' => 1,
            ]);
            $foreignGuest = $otherReservation->guests()->create(['name' => 'Outside guest']);
            $item->update(['reservation_guest_id' => $foreignGuest->id]);
        } else {
            $restaurant = Restaurant::create(['name' => 'Other', 'slug' => 'other', 'address' => 'Other']);
            $category = $restaurant->menuCategories()->create(['name' => 'Other', 'slug' => 'other']);
            $foreignDish = $category->items()->create(['name' => 'Foreign dish', 'price' => '1.00']);
            $item->update(['menu_item_id' => $foreignDish->id]);
        }
        $before = $this->preOrder->items()->orderBy('id')->get()->toArray();

        $this->actingAs($this->owner)->postJson($this->url)->assertUnprocessable()->assertJsonValidationErrors('pre_order');
        $this->assertDraft();
        $this->assertSame($before, $this->preOrder->items()->orderBy('id')->get()->toArray());
    }

    public static function invalidSelections(): array
    {
        return [['zero quantity'], ['negative price'], ['foreign guest'], ['foreign restaurant dish']];
    }

    public function test_summary_failure_rolls_back_submission_status_and_timestamp(): void
    {
        $this->mock(ConsolidatedPreOrderService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('summarize')->once()->andThrow(new RuntimeException('Summary failed.'));
        });

        try {
            app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner);
            $this->fail('The summary failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Summary failed.', $exception->getMessage());
        }

        $this->assertDraft();
    }

    public function test_service_rechecks_ownership_on_the_locked_reservation(): void
    {
        $newOwner = User::factory()->create();
        Reservation::whereKey($this->reservation->id)->update(['owner_user_id' => $newOwner->id]);

        try {
            app(GroupPreOrderSubmissionService::class)->submit($this->reservation, $this->owner);
            $this->fail('The stale owner must not be authorized.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(403, $exception->status() ?? 403);
        }

        $this->assertDraft();
    }

    public function test_successful_submission_locks_guest_updates_and_clearing_but_keeps_reads_available(): void
    {
        $before = $this->preOrder->items()->orderBy('id')->get()->toArray();
        $this->actingAs($this->owner)->postJson($this->url)->assertOk();
        $url = route('invitations.selections.update', ['token' => $this->guestToken]);

        $this->putJson($url, ['items' => [['menu_item_id' => $this->dish->id, 'quantity' => 5]]], ['If-Match' => '"0"'])->assertConflict();
        $this->putJson($url, ['items' => []], ['If-Match' => '"0"'])->assertConflict();
        $this->getJson(route('invitations.selections.show', ['token' => $this->guestToken]))->assertOk()
            ->assertJsonPath('items.0.unit_price', '12.50')->assertJsonPath('total_quantity', 2);

        $this->assertSame($before, $this->preOrder->items()->orderBy('id')->get()->toArray());
    }

    private function assertDraft(): void
    {
        $this->preOrder->refresh();
        $this->assertSame('draft', $this->preOrder->status);
        $this->assertNull($this->preOrder->submitted_at);
    }
}
