<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GuestInvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Restaurant $restaurant;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->restaurant = Restaurant::create([
            'name' => 'Green Tasty Test',
            'slug' => 'green-tasty-test',
            'address' => 'Private Address',
            'phone' => 'Private Phone',
        ]);
        $this->reservation = $this->createReservation();
    }

    public function test_owner_creates_an_individual_invitation_and_only_its_hash_is_persisted(): void
    {
        $response = $this->actingAs($this->owner)->postJson($this->storeUrl(), [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'phone' => '+380123456789',
            'invitation_token' => 'attacker-controlled',
            'user_id' => $this->owner->id,
            'reservation_id' => 999999,
            'status' => 'accepted',
        ])->assertCreated()->assertJsonStructure(['guest_id', 'name', 'expires_at', 'invitation_url']);

        $guest = ReservationGuest::findOrFail($response->json('guest_id'));
        $token = $this->tokenFromUrl($response->json('invitation_url'));

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
        $this->assertSame(hash('sha256', $token), $guest->invitation_token);
        $this->assertNotSame($token, $guest->invitation_token);
        $this->assertSame($this->reservation->id, $guest->reservation_id);
        $this->assertNull($guest->user_id);
        $this->assertSame('pending', $guest->status);
        $this->assertSame('alice@example.com', $guest->email);
        $this->assertTrue($guest->invitation_expires_at->between(now()->addDays(7)->subSeconds(5), now()->addDays(7)->addSeconds(5)));
        $this->assertNull($guest->invitation_revoked_at);
        $this->assertArrayNotHasKey('invitation_token', $guest->toArray());
        $response->assertJsonMissingPath('invitation_token')->assertDontSee($guest->invitation_token);
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_owner_can_choose_a_future_expiration(): void
    {
        $expiration = now()->addHours(2)->startOfSecond();

        $response = $this->actingAs($this->owner)->postJson($this->storeUrl(), [
            'name' => 'Alice',
            'expires_at' => $expiration->toIso8601String(),
        ])->assertCreated();

        $guest = ReservationGuest::findOrFail($response->json('guest_id'));
        $this->assertTrue($guest->invitation_expires_at->equalTo($expiration));
    }

    public function test_only_the_reservation_owner_can_create_revoke_or_regenerate_invitations(): void
    {
        $invitation = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);
        $hash = $guest->invitation_token;
        $this->mock(GuestInvitationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('create', 'regenerate', 'revoke');
        });
        $this->actingAs(User::factory()->create());

        // Authorization must run even before validation of the missing name.
        $this->postJson($this->storeUrl(), [])->assertForbidden();
        $this->postJson($this->guestUrl('regenerate', $guest))->assertForbidden();
        $this->deleteJson($this->guestUrl('destroy', $guest))->assertForbidden();

        $this->assertSame($hash, $guest->fresh()->invitation_token);
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_even_an_authenticated_participant_cannot_manage_invitations(): void
    {
        $invitation = $this->issueInvitation();
        $participant = User::factory()->create();
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);
        $guest->update(['user_id' => $participant->id]);
        $this->actingAs($participant);

        $this->postJson($this->storeUrl(), ['name' => 'Another guest'])->assertForbidden();
        $this->postJson($this->guestUrl('regenerate', $guest))->assertForbidden();
        $this->deleteJson($this->guestUrl('destroy', $guest))->assertForbidden();
    }

    public function test_unauthenticated_users_cannot_manage_invitations(): void
    {
        $invitation = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);

        $this->postJson($this->storeUrl(), ['name' => 'Alice'])->assertUnauthorized();
        $this->postJson($this->guestUrl('regenerate', $guest))->assertUnauthorized();
        $this->deleteJson($this->guestUrl('destroy', $guest))->assertUnauthorized();
    }

    public function test_guest_opens_a_valid_link_without_authentication_and_receives_only_their_public_view(): void
    {
        $invitation = $this->issueInvitation();
        $this->reservation->guests()->create(['name' => 'Other Guest Secret', 'email' => 'private@example.com']);
        $category = $this->restaurant->menuCategories()->create(['name' => 'Main Courses', 'slug' => 'main', 'sort_order' => 2]);
        $emptyCategory = $this->restaurant->menuCategories()->create(['name' => 'Starters', 'slug' => 'starters', 'sort_order' => 1]);
        $dish = $category->items()->create(['name' => 'Vegetable Bowl', 'description' => 'Seasonal vegetables', 'price' => '12.50']);
        $category->items()->create(['name' => 'Unavailable Dish', 'price' => '20.00', 'is_available' => false]);
        $inactive = $this->restaurant->menuCategories()->create(['name' => 'Hidden Category', 'slug' => 'hidden', 'is_active' => false]);
        $inactive->items()->create(['name' => 'Hidden Dish', 'price' => '30.00']);

        $response = $this->getJson($invitation['invitation_url'])->assertOk()->assertExactJson([
            'restaurant' => ['name' => 'Green Tasty Test'],
            'reservation' => ['reserved_at' => $this->reservation->reserved_at->toIso8601String()],
            'guest' => ['name' => 'Alice'],
            'menu_categories' => [
                ['id' => $emptyCategory->id, 'name' => 'Starters', 'dishes' => []],
                ['id' => $category->id, 'name' => 'Main Courses', 'dishes' => [[
                    'id' => $dish->id,
                    'name' => 'Vegetable Bowl',
                    'description' => 'Seasonal vegetables',
                    'price' => '12.50',
                ]]],
            ],
        ]);

        $response->assertDontSee('Other Guest Secret')->assertDontSee('private@example.com');
        $response->assertDontSee('Private Address')->assertDontSee('Private Phone');
        $response->assertDontSee('Unavailable Dish')->assertDontSee('Hidden Dish');
        $response->assertJsonMissingPath('invitation_url')->assertJsonMissingPath('invitation_token');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_browser_access_is_stateless_and_cannot_cache_or_send_the_invitation_as_a_referrer(): void
    {
        $this->withoutVite();
        $invitation = $this->issueInvitation();

        $response = $this->get($invitation['invitation_url'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($this->app['request']->hasSession());
    }

    public function test_invalid_tokens_and_stored_hashes_are_rejected_identically(): void
    {
        $invitation = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);

        foreach (['invalid', str_repeat('a', 64), $guest->invitation_token] as $token) {
            $this->getJson(route('invitations.show', ['token' => $token]))
                ->assertNotFound()
                ->assertExactJson(['message' => 'Invitation not found.'])
                ->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_invitation_expires_at_the_exact_expiration_boundary(): void
    {
        $invitation = $this->issueInvitation();
        $expiration = CarbonImmutable::parse($invitation['expires_at']);
        $this->travelTo($expiration->subSecond());
        $this->getJson($invitation['invitation_url'])->assertOk();

        $this->travelTo($expiration);
        $this->getJson($invitation['invitation_url'])->assertNotFound()->assertExactJson(['message' => 'Invitation not found.']);
    }

    public function test_owner_revokes_an_invitation_without_deleting_the_guest(): void
    {
        $invitation = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);

        $this->actingAs($this->owner)->deleteJson($this->guestUrl('destroy', $guest))->assertNoContent();

        $guest->refresh();
        $this->assertNull($guest->invitation_token);
        $this->assertNotNull($guest->invitation_revoked_at);
        $this->getJson($invitation['invitation_url'])->assertNotFound();
        $this->deleteJson($this->guestUrl('destroy', $guest))->assertNoContent();
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_a_revocation_marker_rejects_a_token_even_if_its_hash_is_still_present(): void
    {
        $invitation = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);
        $guest->invitation_revoked_at = CarbonImmutable::now();
        $guest->save();

        $this->getJson($invitation['invitation_url'])->assertNotFound();
    }

    public function test_an_invitation_without_an_expiration_is_rejected(): void
    {
        $invitation = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);
        $guest->invitation_expires_at = null;
        $guest->save();

        $this->getJson($invitation['invitation_url'])->assertNotFound();
    }

    public function test_regeneration_invalidates_the_previous_token_and_keeps_the_same_guest(): void
    {
        $old = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($old['guest_id']);
        $this->getJson($old['invitation_url'])->assertOk();
        $expiration = now()->addHours(3)->startOfSecond();

        $response = $this->actingAs($this->owner)->postJson($this->guestUrl('regenerate', $guest), [
            'expires_at' => $expiration->toIso8601String(),
        ])->assertOk()->assertJsonPath('guest_id', $guest->id);
        $newUrl = $response->json('invitation_url');

        $guest->refresh();
        $this->assertNotSame($old['invitation_url'], $newUrl);
        $this->assertSame(hash('sha256', $this->tokenFromUrl($newUrl)), $guest->invitation_token);
        $this->assertTrue($guest->invitation_expires_at->equalTo($expiration));
        $this->getJson($old['invitation_url'])->assertNotFound();
        $this->getJson($newUrl)->assertOk()->assertJsonPath('guest.name', 'Alice');
        $response->assertDontSee($guest->invitation_token)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('reservation_guests', 1);
    }

    public function test_regeneration_can_reactivate_a_revoked_invitation(): void
    {
        $old = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($old['guest_id']);
        app(GuestInvitationService::class)->revoke($this->reservation, $guest);

        $response = $this->actingAs($this->owner)->postJson($this->guestUrl('regenerate', $guest))->assertOk();

        $this->assertNull($guest->fresh()->invitation_revoked_at);
        $this->getJson($old['invitation_url'])->assertNotFound();
        $this->getJson($response->json('invitation_url'))->assertOk();
    }

    public function test_nested_owner_routes_cannot_modify_a_guest_from_another_reservation(): void
    {
        $otherReservation = $this->createReservation();
        $invitation = $this->issueInvitation($otherReservation);
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);
        $hash = $guest->invitation_token;
        $this->actingAs($this->owner);

        $this->postJson($this->guestUrl('regenerate', $guest))->assertNotFound();
        $this->deleteJson($this->guestUrl('destroy', $guest))->assertNotFound();

        $this->assertSame($hash, $guest->fresh()->invitation_token);
        $this->getJson($invitation['invitation_url'])->assertOk();
    }

    public function test_guest_token_does_not_grant_access_to_other_reservations_or_owner_summaries(): void
    {
        $invitation = $this->issueInvitation();
        $otherRestaurant = Restaurant::create(['name' => 'Other Restaurant Secret', 'slug' => 'other', 'address' => 'Other Address']);
        $otherReservation = $this->createReservation(User::factory()->create(), $otherRestaurant);
        $otherGuest = $otherReservation->guests()->create(['name' => 'Other Guest Secret']);
        $category = $otherRestaurant->menuCategories()->create(['name' => 'Other Menu Secret', 'slug' => 'other']);
        $category->items()->create(['name' => 'Other Dish Secret', 'price' => '50.00']);

        $this->getJson($invitation['invitation_url'].'?reservation_id='.$otherReservation->id.'&guest_id='.$otherGuest->id)
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Green Tasty Test')
            ->assertJsonPath('guest.name', 'Alice')
            ->assertJsonPath('menu_categories', [])
            ->assertDontSee('Other Restaurant Secret')
            ->assertDontSee('Other Guest Secret');

        $this->getJson(route('reservations.pre-order.summary', $this->reservation))->assertUnauthorized();
        $this->getJson(route('reservations.pre-order.summary', $otherReservation))->assertUnauthorized();
    }

    #[DataProvider('invalidInvitationInput')]
    public function test_invalid_creation_input_is_rejected_without_creating_a_guest(array $input, string $field): void
    {
        $this->actingAs($this->owner)->postJson($this->storeUrl(), $input)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('reservation_guests', 0);
    }

    public static function invalidInvitationInput(): array
    {
        return [
            'missing name' => [[], 'name'],
            'name too long' => [['name' => str_repeat('a', 256)], 'name'],
            'invalid email' => [['name' => 'Alice', 'email' => 'invalid'], 'email'],
            'invalid phone' => [['name' => 'Alice', 'phone' => ['invalid']], 'phone'],
            'invalid expiry' => [['name' => 'Alice', 'expires_at' => 'not-a-date'], 'expires_at'],
            'past expiry' => [['name' => 'Alice', 'expires_at' => '2000-01-01'], 'expires_at'],
            'excessive expiry' => [['name' => 'Alice', 'expires_at' => '2999-01-01'], 'expires_at'],
        ];
    }

    public function test_invalid_regeneration_expiration_does_not_invalidate_the_current_link(): void
    {
        $invitation = $this->issueInvitation();
        $guest = ReservationGuest::findOrFail($invitation['guest_id']);
        $hash = $guest->invitation_token;

        $this->actingAs($this->owner)->postJson($this->guestUrl('regenerate', $guest), ['expires_at' => '2000-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');

        $this->assertSame($hash, $guest->fresh()->invitation_token);
        $this->getJson($invitation['invitation_url'])->assertOk();
    }

    public function test_owner_creation_is_rate_limited_without_creating_an_extra_guest(): void
    {
        $this->actingAs($this->owner);
        foreach (range(1, 20) as $index) {
            $this->postJson($this->storeUrl(), ['name' => 'Guest '.$index])->assertCreated();
        }

        $this->postJson($this->storeUrl(), ['name' => 'Excess guest'])->assertTooManyRequests();
        $this->assertDatabaseCount('reservation_guests', 20);
    }

    public function test_guest_access_is_rate_limited_by_ip_without_using_the_token_as_a_cache_key(): void
    {
        foreach (range(1, 60) as $index) {
            $this->getJson(route('invitations.show', ['token' => 'invalid-'.$index]))->assertNotFound();
        }

        $this->getJson(route('invitations.show', ['token' => 'another-token']))
            ->assertTooManyRequests()->assertHeader('Cache-Control', 'no-store, private');
    }

    private function createReservation(?User $owner = null, ?Restaurant $restaurant = null): Reservation
    {
        return ($restaurant ?? $this->restaurant)->reservations()->create([
            'owner_user_id' => ($owner ?? $this->owner)->id,
            'code' => (string) Str::uuid(),
            'reserved_at' => now()->addDay(),
            'guest_count' => 2,
            'special_requests' => 'Owner-only requests',
        ]);
    }

    /** @return array{guest_id: int, name: string, expires_at: string, invitation_url: string} */
    private function issueInvitation(?Reservation $reservation = null): array
    {
        return app(GuestInvitationService::class)->create($reservation ?? $this->reservation, ['name' => 'Alice']);
    }

    private function storeUrl(): string
    {
        return route('reservations.invitations.store', $this->reservation);
    }

    private function guestUrl(string $action, ReservationGuest $guest): string
    {
        return route('reservations.invitations.'.$action, ['reservation' => $this->reservation, 'guest' => $guest]);
    }

    private function tokenFromUrl(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }
}
