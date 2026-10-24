<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\AuthVerificationCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EndToEndIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_owner_and_anonymous_guests_complete_the_group_order_journey(): void
    {
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 08:00:00', 'UTC'));
        $restaurant = Restaurant::create(['name' => 'Integration Restaurant', 'slug' => 'integration', 'address' => 'Test address']);
        $table = $restaurant->tables()->create(['name' => 'Garden', 'seats' => 4]);
        $category = $restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main']);
        $dish = $category->items()->create(['name' => 'Garden bowl', 'description' => 'Seasonal vegetables', 'price' => '0.10']);
        $category->items()->create(['name' => 'Unavailable', 'price' => '99.99', 'is_available' => false]);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->has('restaurants', 1)->where('restaurants.0.slug', 'integration')));
        $this->get(route('restaurants.show', $restaurant))->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $loaded) => $loaded->has('menu.0.dishes', 1)->where('menu.0.dishes.0.price', '0.10')));
        $this->get(route('restaurants.bookings.create', $restaurant))->assertRedirect(route('login'));

        Notification::fake();
        $this->post(route('register.store'), [
            'name' => 'Journey Owner', 'email' => 'journey@example.test',
            'password' => 'Secure9!Pass', 'password_confirmation' => 'Secure9!Pass',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $owner = User::query()->where('email', 'journey@example.test')->sole();
        $this->assertGuest();
        Notification::assertSentOnDemand(AuthVerificationCode::class, function (AuthVerificationCode $notification): bool {
            $this->postJson(route('auth.code.verify'), ['purpose' => 'registration', 'code' => $notification->code])->assertOk();

            return true;
        });
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'Secure9!Pass'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($owner);

        $booking = ['date' => '2026-10-24', 'time' => '12:00', 'guest_count' => 3, 'restaurant_table_id' => $table->id];
        $availability = array_diff_key($booking, ['restaurant_table_id' => true]);
        $this->getJson(route('restaurants.availability', $restaurant).'?'.http_build_query($availability))
            ->assertOk()->assertJsonPath('tables.0.id', $table->id);
        $this->post(route('restaurants.bookings.store', $restaurant), $booking)->assertSessionHasNoErrors()->assertRedirect();
        $reservation = Reservation::query()->sole();
        $this->assertSame('2026-10-24 08:00:00', $reservation->getRawOriginal('reserved_at'));
        $this->assertSame($owner->id, $reservation->owner_user_id);
        $this->assertSame($owner->id, $reservation->guests()->sole()->user_id);
        $this->get(route('reservations.confirmation', $reservation))->assertInertia(fn (Assert $page) => $page
            ->where('reservation.time', '12:00')->where('reservation.timezone', 'Asia/Tbilisi'));
        $this->postJson(route('restaurants.bookings.store', $restaurant), $booking)->assertUnprocessable();
        $this->assertDatabaseCount('reservations', 1);
        $this->postJson(route('reservations.pre-order.submit', $reservation))->assertUnprocessable();
        $this->assertDatabaseCount('pre_orders', 0);

        $tokens = [];
        foreach (['Alice', 'Bob'] as $name) {
            $issued = $this->postJson(route('reservations.invitations.store', $reservation), ['name' => $name])
                ->assertCreated()->assertJsonMissingPath('invitation_token')->json();
            $tokens[] = $token = basename(parse_url($issued['invitation_url'], PHP_URL_PATH));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
            $this->assertSame(hash('sha256', $token), ReservationGuest::findOrFail($issued['guest_id'])->invitation_token);
        }
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
        [$alice, $bob] = $tokens;
        $this->getJson(route('invitations.menu', $alice))->assertOk()->assertJsonPath('selections.subtotal', '0.00')
            ->assertJsonPath('invitation.guest.name', 'Alice')->assertDontSee('Bob')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $selection = fn (int $quantity): array => ['items' => [['menu_item_id' => $dish->id, 'quantity' => $quantity]]];
        $this->putJson(route('invitations.selections.update', $alice), $selection(0))->assertUnprocessable();
        $this->putJson(route('invitations.selections.update', $alice), $selection(1))->assertOk()->assertJsonPath('subtotal', '0.10');
        $dish->update(['price' => '0.20']); // Simulate a legitimate menu price change between saves.
        $this->putJson(route('invitations.selections.update', $alice), $selection(3))->assertOk()->assertJsonPath('subtotal', '0.30');
        $this->putJson(route('invitations.selections.update', $bob), $selection(2))->assertOk()->assertJsonPath('subtotal', '0.40');
        $this->putJson(route('invitations.selections.update', $bob), ['items' => []])->assertOk()->assertJsonPath('subtotal', '0.00');
        $this->putJson(route('invitations.selections.update', $bob), $selection(2))->assertOk();
        $this->getJson(route('invitations.selections.show', $alice))->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.unit_price', '0.10')->assertJsonPath('items.0.quantity', 3)->assertJsonPath('subtotal', '0.30');
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'Secure9!Pass'])->assertRedirect();
        $this->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
            ->where('canSubmitPreOrder', true)->loadDeferredProps(fn (Assert $loaded) => $loaded
            ->has('groupOrder.participants', 3)->where('groupOrder.summary.grand_total', '0.70')));
        $this->getJson(route('reservations.pre-order.summary', $reservation))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.quantity', 5)->assertJsonPath('grand_total', '0.70');
        $submitted = $this->postJson(route('reservations.pre-order.submit', $reservation))->assertOk()
            ->assertJsonPath('status', 'submitted')->assertJsonPath('grand_total', '0.70')->json();
        $this->assertNotNull($submitted['submitted_at']);
        $dish->update(['price' => '99.99']);
        $this->travel(1)->minutes();
        $this->postJson(route('reservations.pre-order.submit', $reservation))->assertOk()->assertExactJson($submitted);
        $this->post(route('logout'))->assertRedirect();
        $this->getJson(route('invitations.menu', $alice))->assertOk()->assertJsonPath('order.editable', false)
            ->assertJsonPath('order.status', 'submitted')->assertJsonPath('selections.subtotal', '0.30')->assertDontSee('Bob');
        $this->putJson(route('invitations.selections.update', $alice), $selection(9))->assertConflict();
        $this->putJson(route('invitations.selections.update', $bob), ['items' => []])->assertConflict();
        $this->assertDatabaseCount('pre_orders', 1);
        $this->assertDatabaseCount('pre_order_items', 2);
    }

    public function test_bearer_and_owner_boundaries_hold_across_reservations_and_token_rotation(): void
    {
        $this->withoutVite();
        $owners = User::factory()->count(2)->create();
        $restaurant = Restaurant::create(['name' => 'Isolation', 'slug' => 'isolation', 'address' => 'Test']);
        $dish = $restaurant->menuCategories()->create(['name' => 'Main', 'slug' => 'main'])->items()->create(['name' => 'Bowl', 'price' => '12.50']);
        $reservations = [];
        $invitations = [];
        foreach ($owners as $index => $owner) {
            $reservations[] = $reservation = $restaurant->reservations()->create([
                'owner_user_id' => $owner->id, 'code' => 'GT-ISOLATION-'.$index, 'reserved_at' => now()->addDay(), 'guest_count' => 1,
            ]);
            $invitations[] = $this->actingAs($owner)->postJson(route('reservations.invitations.store', $reservation), ['name' => 'Private guest '.$index])
                ->assertCreated()->json();
        }
        [$first, $second] = $reservations;
        $alice = basename(parse_url($invitations[0]['invitation_url'], PHP_URL_PATH));
        $bob = basename(parse_url($invitations[1]['invitation_url'], PHP_URL_PATH));
        $this->actingAs($owners[1])->getJson(route('reservations.show', $first))->assertForbidden()->assertDontSee('Private guest 0');
        $this->getJson(route('reservations.pre-order.summary', $first))->assertForbidden();
        $this->postJson(route('reservations.pre-order.submit', $first))->assertForbidden();
        $this->deleteJson(route('reservations.invitations.destroy', [$second, $invitations[0]['guest_id']]))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $payload = ['items' => [['menu_item_id' => $dish->id, 'quantity' => 2]]];
        $this->putJson(route('invitations.selections.update', $alice), $payload)->assertOk();
        $this->getJson(route('invitations.selections.show', $bob).'?guest_id='.$invitations[0]['guest_id'])->assertOk()
            ->assertExactJson(['items' => [], 'total_quantity' => 0, 'subtotal' => '0.00']);
        $this->actingAs($owners[0]);
        $regenerated = $this->postJson(route('reservations.invitations.regenerate', [$first, $invitations[0]['guest_id']]))->assertOk()->json();
        $replacement = basename(parse_url($regenerated['invitation_url'], PHP_URL_PATH));
        $this->getJson(route('invitations.menu', $alice))->assertNotFound();
        $this->putJson(route('invitations.selections.update', $alice), ['items' => []])->assertNotFound();
        $this->getJson(route('invitations.selections.show', $replacement))->assertOk()->assertJsonPath('subtotal', '25.00');
        $this->deleteJson(route('reservations.invitations.destroy', [$first, $invitations[0]['guest_id']]))->assertNoContent();
        $this->getJson(route('invitations.menu', $replacement))->assertNotFound();
        $this->getJson(route('reservations.pre-order.summary', $first))->assertOk()->assertJsonPath('grand_total', '25.00');
        $this->actingAs($owners[1])->getJson(route('reservations.pre-order.summary', $second))->assertOk()->assertJsonPath('grand_total', '0.00');
        $this->assertDatabaseCount('pre_order_items', 1);
    }
}
