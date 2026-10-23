<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\ConsolidatedPreOrderService;
use App\Services\ReservationDashboardService;
use App\Services\RestaurantBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ReservationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Restaurant $restaurant;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00:00', 'UTC'));
        $this->owner = User::factory()->create();
        $this->restaurant = Restaurant::create(['name' => 'Green Tasty', 'slug' => 'dashboard', 'address' => 'Tbilisi']);
        $table = $this->restaurant->tables()->create(['name' => 'Table 2', 'seats' => 4]);
        $this->reservation = $this->restaurant->reservations()->create([
            'owner_user_id' => $this->owner->id, 'restaurant_table_id' => $table->id,
            'code' => 'GT-DASHBOARD', 'reserved_at' => '2026-10-24 08:00:00', 'guest_count' => 3,
            'status' => 'confirmed', 'special_requests' => 'Private request',
        ]);
    }

    public function test_owner_receives_local_reservation_information_and_a_deferred_group_order(): void
    {
        $this->mock(ReservationDashboardService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('groupOrder'));
        $this->actingAs($this->owner)->get($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('reservations/Show')
                ->where('reservation.id', $this->reservation->id)
                ->where('reservation.restaurant.name', 'Green Tasty')->where('reservation.restaurant.address', 'Tbilisi')
                ->where('reservation.date', '2026-10-24')->where('reservation.time', '12:00')
                ->where('reservation.end_time', '14:00')->where('reservation.timezone', 'Asia/Tbilisi')
                ->where('reservation.table.name', 'Table 2')->where('reservation.guest_count', 3)
                ->where('reservation.status', 'confirmed')->missing('reservation.owner_user_id')
                ->missing('reservation.special_requests')->missing('groupOrder'));
    }

    public function test_owner_reads_aggregates_saved_prices_and_guest_totals_without_private_invitation_data(): void
    {
        [$ownerParticipant, $alice, $dish] = $this->populate();
        $alice->forceFill(['invitation_token' => hash('sha256', 'private-token'), 'invitation_expires_at' => now()->addDay()])->save();
        $this->actingAs($this->owner)->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('groupOrder.status', 'draft')->where('groupOrder.submitted_at', null)
                ->where('groupOrder.participants', [
                    ['id' => $ownerParticipant->id, 'name' => $this->owner->name, 'is_owner' => true, 'response_status' => 'pending', 'invitation_status' => 'owner', 'invitation_expires_at' => null],
                    ['id' => $alice->id, 'name' => 'Alice', 'is_owner' => false, 'response_status' => 'accepted', 'invitation_status' => 'active', 'invitation_expires_at' => '2026-10-24T08:00:00+00:00'],
                ])
                ->where('groupOrder.summary.items', [['menu_item_id' => $dish->id, 'name' => 'Vegetable Bowl', 'quantity' => 3, 'subtotal' => '34.50']])
                ->where('groupOrder.summary.guests.0.items.0.unit_price', '12.50')
                ->where('groupOrder.summary.guests.1.items.0.unit_price', '11.00')
                ->where('groupOrder.summary.guests.1.items.0.subtotal', '22.00')
                ->where('groupOrder.summary.guests.0.subtotal', '12.50')
                ->where('groupOrder.summary.total_quantity', 3)->where('groupOrder.summary.grand_total', '34.50')
                ->missing('groupOrder.participants.1.email')->missing('groupOrder.participants.1.phone')
                ->missing('groupOrder.participants.1.user_id')->missing('groupOrder.participants.1.invitation_token')
                ->missing('groupOrder.participants.1.invitation_url')->where('groupOrder.participants.1.invitation_expires_at', '2026-10-24T08:00:00+00:00')));
        $this->get($this->url(), $this->deferredHeaders())->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee($alice->invitation_token)->assertDontSee('private-token')
            ->assertDontSee('private-guest@example.test')->assertDontSee('+995555000001');
    }

    public function test_anonymous_requests_cannot_access_the_dashboard_even_with_an_invitation_token(): void
    {
        $this->get($this->url().'?token='.str_repeat('a', 64))->assertRedirect(route('login'));
        $this->getJson($this->url())->assertUnauthorized()->assertJsonMissingPath('props.reservation');
    }

    public function test_a_different_user_is_denied_before_any_private_information_is_prepared(): void
    {
        $this->mock(RestaurantBookingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('confirmation'));
        $this->mock(ReservationDashboardService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('groupOrder'));
        $this->actingAs(User::factory()->create())->getJson($this->url())->assertForbidden()
            ->assertDontSee('GT-DASHBOARD')->assertJsonMissingPath('props.reservation');
    }

    public function test_an_authenticated_participant_is_not_authorized_as_the_owner(): void
    {
        $participant = User::factory()->create();
        $this->reservation->guests()->create(['name' => 'Participant', 'user_id' => $participant->id]);
        $this->actingAs($participant)->getJson($this->url())->assertForbidden()->assertJsonMissingPath('props.groupOrder');
        $this->getJson(route('reservations.pre-order.summary', $this->reservation))->assertForbidden();
    }

    public function test_deferred_requests_reauthorize_instead_of_relying_on_the_previous_page(): void
    {
        $this->actingAs($this->owner);
        $headers = $this->deferredHeaders();
        $this->mock(ReservationDashboardService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('groupOrder'));
        $this->actingAs(User::factory()->create())->get($this->url(), $headers)->assertForbidden()->assertDontSee('GT-DASHBOARD');
    }

    public function test_missing_reservations_return_404_without_loading_the_summary(): void
    {
        $this->mock(ReservationDashboardService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('groupOrder'));
        $this->actingAs($this->owner)->getJson(route('reservations.show', 999))->assertNotFound();
    }

    public function test_empty_summary_does_not_create_an_order_or_participants(): void
    {
        $this->actingAs($this->owner)->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('groupOrder.participants', [])->where('groupOrder.status', null)->where('groupOrder.submitted_at', null)
                ->where('groupOrder.summary.pre_order_id', null)->where('groupOrder.summary.items', [])
                ->where('groupOrder.summary.guests', [])->where('groupOrder.summary.total_quantity', 0)
                ->where('groupOrder.summary.grand_total', '0.00')));
        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertDatabaseCount('reservation_guests', 0);
    }

    public function test_an_empty_draft_and_participants_without_selections_remain_visible(): void
    {
        $this->reservation->preOrder()->create(['status' => 'draft']);
        $this->reservation->guests()->create(['name' => 'No selections']);
        $this->actingAs($this->owner)->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('groupOrder.status', 'draft')->has('groupOrder.participants', 1)
                ->where('groupOrder.participants.0.invitation_status', 'not_invited')
                ->where('groupOrder.summary.items', [])->where('groupOrder.summary.grand_total', '0.00')));
    }

    public function test_submitted_status_and_timestamp_are_preserved_without_mutating_the_order(): void
    {
        $this->populate();
        $order = $this->reservation->preOrder()->firstOrFail();
        $order->update(['status' => 'submitted', 'submitted_at' => now()]);
        $before = $order->fresh()->getAttributes();
        $this->actingAs($this->owner)->get($this->url())->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('groupOrder.status', 'submitted')->where('groupOrder.submitted_at', '2026-10-23T08:00:00+00:00')
                ->where('groupOrder.summary.grand_total', '34.50')));
        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    public function test_invitation_statuses_follow_expiration_and_revocation_without_returning_secrets(): void
    {
        $cases = [
            ['name' => 'Active', 'invitation_token' => hash('sha256', 'active'), 'invitation_expires_at' => now()->addSecond(), 'expected' => 'active'],
            ['name' => 'Expired', 'invitation_token' => hash('sha256', 'expired'), 'invitation_expires_at' => now(), 'expected' => 'expired'],
            ['name' => 'Revoked', 'invitation_token' => null, 'invitation_revoked_at' => now(), 'expected' => 'revoked'],
            ['name' => 'Not invited', 'expected' => 'not_invited'],
            ['name' => 'Missing expiry', 'invitation_token' => hash('sha256', 'no-expiry'), 'expected' => 'unavailable'],
        ];
        foreach ($cases as $case) {
            $expected = $case['expected'];
            unset($case['expected']);
            $guest = $this->reservation->guests()->create(['name' => $case['name']]);
            $guest->forceFill($case)->save();
            $this->actingAs($this->owner);
            $data = app(ReservationDashboardService::class)->groupOrder($this->reservation);
            $this->assertSame($expected, collect($data['participants'])->firstWhere('id', $guest->id)['invitation_status']);
            $this->assertArrayNotHasKey('invitation_token', collect($data['participants'])->firstWhere('id', $guest->id));
        }
    }

    public function test_summary_and_participants_are_isolated_even_if_an_order_has_a_cross_linked_guest_item(): void
    {
        [, , $dish] = $this->populate();
        $other = $this->restaurant->reservations()->create([
            'owner_user_id' => User::factory()->create()->id, 'code' => 'GT-OTHER', 'reserved_at' => now()->addDay(), 'guest_count' => 1,
        ]);
        $guest = $other->guests()->create(['name' => 'Other private guest']);
        $this->reservation->preOrder()->firstOrFail()->items()->create([
            'reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => 50, 'unit_price' => '99.00',
        ]);
        $this->actingAs($this->owner)->get($this->url(), $this->deferredHeaders())->assertOk()
            ->assertJsonCount(2, 'props.groupOrder.participants')->assertJsonCount(2, 'props.groupOrder.summary.guests')
            ->assertJsonPath('props.groupOrder.summary.grand_total', '34.50')->assertDontSee('Other private guest')->assertDontSee('GT-OTHER');
    }

    public function test_summary_errors_are_rescued_without_private_details_and_retry_recovers(): void
    {
        Exceptions::fake();
        $this->actingAs($this->owner);
        $headers = $this->deferredHeaders();
        $exception = new RuntimeException('Private SQL and token details');
        $this->mock(ReservationDashboardService::class, function (MockInterface $mock) use ($exception): void {
            $mock->shouldReceive('groupOrder')->once()->andThrow($exception);
            $mock->shouldReceive('groupOrder')->once()->andReturn(['participants' => [], 'summary' => [], 'status' => null, 'submitted_at' => null]);
        });
        $this->get($this->url(), $headers)->assertOk()->assertJsonPath('rescuedProps', ['groupOrder'])
            ->assertJsonMissingPath('props.groupOrder')->assertDontSee($exception->getMessage());
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
        $this->get($this->url(), $headers)->assertOk()->assertJsonPath('props.groupOrder.participants', [])->assertJsonMissingPath('rescuedProps');
    }

    public function test_read_queries_are_bounded_and_all_money_is_delegated_to_the_existing_summary_service(): void
    {
        $this->populate();
        $this->actingAs($this->owner);
        $summary = app(ConsolidatedPreOrderService::class)->summarize($this->reservation);
        $this->mock(ConsolidatedPreOrderService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('summarize')->once()->withArgs(fn (Reservation $reservation): bool => $reservation->is($this->reservation))->andReturn($summary));
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $data = app(ReservationDashboardService::class)->groupOrder($this->reservation);
            $this->assertSame($summary, $data['summary']);
            $queries = collect(DB::getQueryLog())->pluck('query');
            $this->assertCount(3, $queries->filter(fn (string $query): bool => str_starts_with(strtolower($query), 'select')));
            $this->assertFalse($queries->contains(fn (string $query): bool => preg_match('/^(insert|update|delete)\b/i', $query) === 1));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_query_count_stays_constant_as_participants_and_selections_grow(): void
    {
        [, , $dish] = $this->populate();
        $order = $this->reservation->preOrder()->firstOrFail();
        for ($index = 0; $index < 12; $index++) {
            $guest = $this->reservation->guests()->create(['name' => 'Guest '.$index]);
            $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => 1, 'unit_price' => '1.00']);
        }
        $this->actingAs($this->owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $data = app(ReservationDashboardService::class)->groupOrder($this->reservation);
            $this->assertCount(14, $data['participants']);
            $this->assertCount(14, $data['summary']['guests']);
            $this->assertSame('46.50', $data['summary']['grand_total']);
            $this->assertCount(7, collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_cancelled_reservations_without_an_assigned_table_still_have_an_owner_read_view(): void
    {
        $this->reservation->update(['status' => 'cancelled', 'restaurant_table_id' => null, 'reserved_at' => '2026-10-24 19:30:00']);
        $this->actingAs($this->owner)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reservation.status', 'cancelled')->where('reservation.table', null)
            ->where('reservation.time', '23:30')->where('reservation.end_date', '2026-10-25')->where('reservation.end_time', '01:30'));
    }

    private function populate(): array
    {
        $ownerParticipant = $this->reservation->guests()->create(['user_id' => $this->owner->id, 'name' => $this->owner->name]);
        $alice = $this->reservation->guests()->create(['name' => 'Alice', 'status' => 'accepted', 'email' => 'private-guest@example.test', 'phone' => '+995555000001']);
        $category = $this->restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $dish = $category->items()->create(['name' => 'Vegetable Bowl', 'price' => '99.99']);
        $order = $this->reservation->preOrder()->create(['status' => 'draft']);
        foreach ([[$ownerParticipant, 1, '12.50'], [$alice, 2, '11.00']] as [$guest, $quantity, $price]) {
            $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => $quantity, 'unit_price' => $price]);
        }

        return [$ownerParticipant, $alice, $dish];
    }

    private function url(): string
    {
        return route('reservations.show', $this->reservation);
    }

    private function deferredHeaders(): array
    {
        return [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $this->get($this->url())->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'reservations/Show', 'X-Inertia-Partial-Data' => 'groupOrder',
        ];
    }
}
