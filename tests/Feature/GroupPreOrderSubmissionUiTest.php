<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GroupPreOrderSubmissionService;
use App\Services\GuestInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class GroupPreOrderSubmissionUiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $restaurant = Restaurant::create(['name' => 'Green Tasty', 'slug' => 'submission-ui', 'address' => 'Test Address']);
        $this->reservation = $restaurant->reservations()->create(['owner_user_id' => $this->owner->id, 'code' => 'GT-SUBMIT-UI', 'reserved_at' => now()->addDay(), 'guest_count' => 2, 'status' => 'confirmed']);
    }

    public function test_dashboard_exposes_the_submission_policy_independently_of_invitation_management(): void
    {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'manageInvitations' ? false : null);
        $this->actingAs($this->owner)->get(route('reservations.show', $this->reservation))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canSubmitPreOrder', true)->where('canManageInvitations', false)->missing('groupOrder'));
    }

    public function test_denied_submission_ability_is_exposed_as_false_and_enforced_by_the_existing_endpoint(): void
    {
        Gate::before(fn (User $user, string $ability): ?bool => $ability === 'submitPreOrder' ? false : null);
        $this->mock(GroupPreOrderSubmissionService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('submit'));
        $this->actingAs($this->owner)->get(route('reservations.show', $this->reservation))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canSubmitPreOrder', false)->where('canManageInvitations', true));
        $this->postJson(route('reservations.pre-order.submit', $this->reservation))->assertForbidden();
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_dashboard_submission_refresh_and_anonymous_guest_lock_preserve_exact_saved_prices(): void
    {
        $invitation = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Alice', 'email' => 'private@example.test']);
        $token = basename(parse_url($invitation['invitation_url'], PHP_URL_PATH));
        $alice = $this->reservation->guests()->findOrFail($invitation['guest_id']);
        $bob = $this->reservation->guests()->create(['name' => 'Bob']);
        $category = $this->reservation->restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $dish = $category->items()->create(['name' => 'Vegetable Bowl', 'price' => '99.99']);
        $order = $this->reservation->preOrder()->create(['status' => 'draft']);
        foreach ([[$alice, 3, '0.10'], [$bob, 2, '0.20']] as [$guest, $quantity, $price]) {
            $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => $quantity, 'unit_price' => $price]);
        }
        $before = $order->items()->orderBy('id')->get()->toArray();
        $this->actingAs($this->owner)->get(route('reservations.show', $this->reservation))->assertInertia(fn (Assert $page) => $page
            ->where('canSubmitPreOrder', true)->loadDeferredProps(fn (Assert $loaded) => $loaded
            ->where('groupOrder.status', 'draft')->where('groupOrder.summary.grand_total', '0.70')));

        $result = $this->postJson(route('reservations.pre-order.submit', $this->reservation))->assertOk()
            ->assertJsonPath('status', 'submitted')->assertJsonPath('grand_total', '0.70')->assertJsonPath('total_quantity', 5)
            ->assertJsonPath('guests.0.items.0.unit_price', '0.10')->assertJsonPath('guests.1.items.0.unit_price', '0.20')
            ->assertDontSee($token)->assertDontSee($alice->invitation_token)->assertDontSee('private@example.test')->json();
        $this->travel(1)->minutes();
        $this->postJson(route('reservations.pre-order.submit', $this->reservation))->assertOk()->assertExactJson($result);

        $dashboard = $this->get(route('reservations.show', $this->reservation))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->where('groupOrder.status', 'submitted')
                ->where('groupOrder.submitted_at', $result['submitted_at'])->where('groupOrder.summary.grand_total', '0.70')
                ->where('groupOrder.summary.guests.0.subtotal', '0.30')->where('groupOrder.summary.guests.1.subtotal', '0.40')));
        $dashboard->assertHeader('Cache-Control', 'no-store, private');

        $this->app['auth']->forgetGuards();
        $this->getJson(route('invitations.menu', $token))->assertOk()->assertJsonPath('order.editable', false)
            ->assertJsonPath('order.status', 'submitted')->assertJsonPath('order.submitted_at', $result['submitted_at'])
            ->assertJsonPath('selections.subtotal', '0.30')->assertJsonCount(1, 'selections.items')->assertDontSee('Bob');
        $this->putJson(route('invitations.selections.update', $token), ['items' => [['menu_item_id' => $dish->id, 'quantity' => 9]]])->assertConflict();
        $this->putJson(route('invitations.selections.update', $token), ['items' => []])->assertConflict();
        $this->assertGuest();
        $this->assertSame($before, $order->items()->orderBy('id')->get()->toArray());
    }

    public function test_other_users_and_invitation_bearers_cannot_load_the_dashboard_or_submit(): void
    {
        $invitation = app(GuestInvitationService::class)->create($this->reservation, ['name' => 'Private Guest']);
        $token = basename(parse_url($invitation['invitation_url'], PHP_URL_PATH));
        $this->postJson(route('reservations.pre-order.submit', $this->reservation).'?token='.$token)->assertUnauthorized()->assertDontSee('Private Guest');
        $this->getJson(route('reservations.show', $this->reservation))->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(route('reservations.show', $this->reservation))->assertForbidden()->assertJsonMissingPath('props.canSubmitPreOrder');
        $this->postJson(route('reservations.pre-order.submit', $this->reservation))->assertForbidden();
        $this->assertDatabaseCount('pre_orders', 0);
    }
}
