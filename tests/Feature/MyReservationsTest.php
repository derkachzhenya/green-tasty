<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\MyReservationsService;
use App\Services\OwnerReservationCancellation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MyReservationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->restaurant = Restaurant::create(['name' => 'Green Tasty Garden', 'slug' => 'garden', 'address' => 'Garden address']);
    }

    public function test_reservations_url_and_authentication_redirect(): void
    {
        $this->assertSame('/reservations', route('dashboard', absolute: false));
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->getJson(route('dashboard'))->assertUnauthorized();
        $response = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('auth.user.id', $this->owner->id)->missing('reservations'));
        $this->assertTrue($response->viewData('page')['encryptHistory']);
    }

    public function test_account_dashboard_opens_the_owned_reservation_cards(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->getJson('/dashboard')->assertUnauthorized();
        $this->reservation();
        $this->reservation(['owner_user_id' => User::factory()->create()->id]);
        $this->actingAs($this->owner)->get(route('account.dashboard'))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('dashboardPage', true)
                ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservations.total', 1)));
    }

    public function test_account_dashboard_pagination_stays_on_dashboard(): void
    {
        for ($index = 0; $index < 7; $index++) {
            $this->reservation();
        }
        $this->actingAs($this->owner)->get(route('account.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('reservations.next_page_url', route('account.dashboard', ['page' => 2]))));
    }

    public function test_a_new_owner_receives_an_empty_paginated_result_without_creating_records(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservations', [
                'data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 6, 'total' => 0,
                'from' => null, 'to' => null, 'prev_page_url' => null, 'next_page_url' => null,
            ])));
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_sign_in_invalidates_old_history_before_rendering_an_encrypted_owner_page(): void
    {
        $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect();
        $response = $this->get(route('dashboard'))->assertOk();
        $this->assertTrue($response->viewData('page')['clearHistory']);
        $this->assertTrue($response->viewData('page')['encryptHistory']);
        $this->assertArrayNotHasKey('clearHistory', $this->get(route('dashboard'))->viewData('page'));
    }

    public function test_rows_reuse_booking_information_and_exclude_guest_and_model_secrets(): void
    {
        $table = $this->restaurant->tables()->create(['name' => 'Table 2', 'seats' => 4]);
        $reservation = $this->reservation(['restaurant_table_id' => $table->id, 'special_requests' => 'Private request']);
        $reservation->preOrder()->create(['status' => 'submitted', 'submitted_at' => now()]);
        $reservation->guests()->create(['name' => 'Private Guest', 'email' => 'guest@example.test'])
            ->forceFill(['invitation_token' => hash('sha256', 'private-raw-token'), 'invitation_expires_at' => now()->addDay()])->save();

        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservations.data', [[
                'id' => $reservation->id, 'code' => $reservation->code, 'status' => 'confirmed', 'guest_count' => 3,
                'restaurant' => ['name' => 'Green Tasty Garden', 'slug' => 'garden', 'address' => 'Garden address'],
                'table' => ['name' => 'Table 2', 'seats' => 4],
                'date' => '2026-10-24', 'time' => '12:00', 'end_date' => '2026-10-24', 'end_time' => '14:00',
                'timezone' => 'Asia/Tbilisi', 'pre_order_status' => 'submitted',
                'active_participants' => 1, 'removed_participants' => 0, 'has_pre_order_updates' => false, 'can_cancel' => false, 'cancel_version' => OwnerReservationCancellation::version($reservation),
                'phase' => 'completed', 'submitted_pre_orders' => 0, 'can_invite' => false, 'can_add_pre_order' => false, 'can_view_group' => false, 'feedback_action' => 'leave',
            ]])->missing('reservations.data.0.guests')->missing('reservations.data.0.owner_user_id')
                ->missing('reservations.data.0.special_requests')->missing('reservations.data.0.pre_order')));
        $result = app(MyReservationsService::class)->forOwner($this->owner);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['Private Guest', 'guest@example.test', 'private-raw-token', hash('sha256', 'private-raw-token'), 'Private request'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertDatabaseCount('pre_orders', 1);
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_only_owned_reservations_are_visible_even_to_an_authenticated_participant(): void
    {
        $otherOwner = User::factory()->create();
        $foreign = $this->reservation(['owner_user_id' => $otherOwner->id]);
        $foreign->guests()->create(['user_id' => $this->owner->id, 'name' => $this->owner->name]);
        $own = $this->reservation();
        $this->actingAs($this->owner)->get(route('dashboard', ['owner_user_id' => $otherOwner->id, 'user_id' => $otherOwner->id]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $loaded) => $loaded
                ->where('reservations.total', 1)->has('reservations.data', 1)->where('reservations.data.0.id', $own->id)));
        $this->getJson(route('reservations.show', $foreign))->assertForbidden();
        $this->getJson(route('reservations.pre-order.summary', $foreign))->assertForbidden();
        $this->actingAs($otherOwner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservations.total', 1)->where('reservations.data.0.id', $foreign->id)));
    }

    public function test_pagination_counts_and_page_boundaries_are_owner_scoped_with_stable_ordering(): void
    {
        $ids = [];
        for ($index = 0; $index < 13; $index++) {
            $ids[] = $this->reservation()->id;
        }
        $otherOwner = User::factory()->create();
        for ($index = 0; $index < 8; $index++) {
            $this->reservation(['owner_user_id' => $otherOwner->id]);
        }
        $expected = array_reverse($ids); // Identical dates use descending IDs as the tiebreaker.
        $this->actingAs($this->owner);
        foreach ([1, 2, 3] as $number) {
            $this->get(route('dashboard', ['page' => $number, 'owner_user_id' => $otherOwner->id, 'token' => 'do-not-propagate']))
                ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $loaded) => $loaded
                    ->where('reservations.current_page', $number)->where('reservations.last_page', 3)
                    ->where('reservations.total', 13)->where('reservations.per_page', 6)
                    ->where('reservations.data', fn ($rows): bool => $rows->pluck('id')->all() === array_slice($expected, ($number - 1) * 6, 6))
                    ->where('reservations.prev_page_url', $number === 1 ? null : route('dashboard', ['page' => $number - 1]))
                    ->where('reservations.next_page_url', $number === 3 ? null : route('dashboard', ['page' => $number + 1]))));
        }
    }

    public function test_deferred_requests_use_current_authentication_after_an_account_change(): void
    {
        $first = $this->reservation();
        $otherOwner = User::factory()->create();
        $second = $this->reservation(['owner_user_id' => $otherOwner->id]);
        $this->actingAs($this->owner);
        $headers = $this->deferredHeaders();

        $this->actingAs($otherOwner)->get(route('dashboard'), $headers)->assertOk()
            ->assertJsonPath('encryptHistory', true)
            ->assertJsonPath('props.reservations.total', 1)->assertJsonPath('props.reservations.data.0.id', $second->id)
            ->assertDontSee($first->code);
        $this->app['auth']->forgetGuards();
        $this->getJson(route('dashboard'), $headers)->assertUnauthorized()->assertDontSee($second->code);
    }

    public function test_reservation_date_precedes_id_when_sorting_and_history_is_not_hidden(): void
    {
        $future = $this->reservation(['reserved_at' => '2027-01-01 08:00:00']);
        $past = $this->reservation(['reserved_at' => '2020-01-01 08:00:00', 'status' => 'completed']);
        $result = app(MyReservationsService::class)->forOwner($this->owner);
        $this->assertSame([$future->id, $past->id], array_column($result['data'], 'id'));
    }

    public function test_out_of_range_page_is_distinct_from_an_owner_with_no_reservations(): void
    {
        $this->reservation();
        $this->actingAs($this->owner)->get(route('dashboard', ['page' => 9]))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservations.data', [])
                ->where('reservations.total', 1)->where('reservations.current_page', 9)->where('reservations.from', null)));
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_page_values_are_rejected_before_querying_private_data(mixed $page): void
    {
        $this->mock(MyReservationsService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('forOwner'));
        $this->actingAs($this->owner)->getJson(route('dashboard', ['page' => $page]))->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public static function invalidPages(): array
    {
        return [[0], [-1], ['abc'], ['1.5'], [['2']], ['2147483648']];
    }

    #[DataProvider('preOrderStates')]
    public function test_actual_group_order_statuses_are_reported_without_inventing_an_order(?string $status): void
    {
        $reservation = $this->reservation();
        if ($status !== null) {
            $reservation->preOrder()->create(['status' => $status]);
        }
        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservations.data.0.pre_order_status', $status)));
        $this->assertDatabaseCount('pre_orders', $status === null ? 0 : 1);
    }

    public static function preOrderStates(): array
    {
        return [[null], ['draft'], ['submitted'], ['cancelled']];
    }

    public function test_midnight_times_deleted_tables_and_inactive_restaurants_remain_readable(): void
    {
        $this->reservation(['reserved_at' => '2026-10-24 19:30:00', 'restaurant_table_id' => null, 'status' => 'cancelled']);
        $this->restaurant->update(['is_active' => false]);
        $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('reservations.data.0.date', '2026-10-24')
                ->where('reservations.data.0.time', '23:30')->where('reservations.data.0.end_date', '2026-10-25')
                ->where('reservations.data.0.end_time', '01:30')->where('reservations.data.0.table', null)
                ->where('reservations.data.0.status', 'cancelled')->where('reservations.data.0.restaurant.name', $this->restaurant->name)));
    }

    public function test_initial_and_deferred_requests_authorize_before_loading_rows(): void
    {
        $this->actingAs($this->owner);
        $headers = $this->deferredHeaders();
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'viewAny' ? false : null);
        $this->mock(MyReservationsService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('forOwner'));
        $this->getJson(route('dashboard'))->assertForbidden();
        $this->get(route('dashboard'), $headers)->assertForbidden();
    }

    public function test_query_count_is_constant_and_reads_never_fetch_guests_or_write_data(): void
    {
        $table = $this->restaurant->tables()->create(['name' => 'Table', 'seats' => 4]);
        $queriesFor = function (int $count) use ($table): int {
            for ($index = 0; $index < $count; $index++) {
                $this->reservation(['restaurant_table_id' => $table->id])->preOrder()->create(['status' => 'draft']);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                app(MyReservationsService::class)->forOwner($this->owner);
                $queries = DB::getQueryLog();
                foreach ($queries as $query) {
                    $this->assertStringStartsWith('select', strtolower($query['query']));
                    $this->assertStringNotContainsString('invitation_token', $query['query']);
                    $this->assertStringNotContainsString('special_requests', $query['query']);
                }

                return count($queries);
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        };
        $this->assertSame(6, $queriesFor(1));
        $this->assertSame(6, $queriesFor(5));
        $this->assertDatabaseCount('reservations', 6);
        $this->assertDatabaseCount('pre_orders', 6);
        $this->assertDatabaseCount('reservation_guests', 0);
    }

    public function test_deferred_failure_is_private_and_retry_recovers(): void
    {
        Exceptions::fake();
        $this->actingAs($this->owner);
        $headers = $this->deferredHeaders();
        $this->mock(MyReservationsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forOwner')->once()->andThrow(new RuntimeException('Private database details'));
            $mock->shouldReceive('forOwner')->once()->andReturn(['data' => [], 'total' => 0]);
        });
        $this->get(route('dashboard'), $headers)->assertOk()->assertJsonPath('rescuedProps', ['reservations'])
            ->assertJsonMissingPath('props.reservations')->assertDontSee('Private database details')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('dashboard'), $headers)->assertOk()->assertJsonPath('props.reservations.data', [])
            ->assertJsonMissingPath('rescuedProps');
    }

    private function reservation(array $attributes = []): Reservation
    {
        return $this->restaurant->reservations()->create(array_merge([
            'owner_user_id' => $this->owner->id, 'code' => 'GT-'.bin2hex(random_bytes(8)),
            'reserved_at' => '2026-10-24 08:00:00', 'guest_count' => 3, 'status' => 'confirmed',
        ], $attributes));
    }

    private function deferredHeaders(): array
    {
        return [
            'X-Inertia' => 'true', 'X-Inertia-Version' => $this->get(route('dashboard'))->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'Dashboard', 'X-Inertia-Partial-Data' => 'reservations',
        ];
    }
}
