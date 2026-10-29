<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\GuestInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SharedInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(): Reservation
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Garden', 'slug' => 'garden', 'address' => 'Garden address']);
        $owner = User::factory()->create();
        $r = $restaurant->reservations()->create(['owner_user_id' => $owner->id, 'code' => 'GT-SHARED', 'reserved_at' => now()->addDays(2), 'guest_count' => 3, 'status' => 'confirmed']);
        $r->guests()->create(['user_id' => $owner->id, 'name' => 'Owner']);

        return $r;
    }

    public function test_owner_issues_one_link_without_creating_a_guest_and_other_users_cannot_issue_it(): void
    {
        $r = $this->reservation();
        $this->actingAs($r->owner)->postJson('/reservations/'.$r->id.'/invite-link')->assertOk()->assertJsonStructure(['invitation_url']);
        $this->assertSame(1, $r->guests()->count());
        $this->actingAs(User::factory()->create())->postJson('/reservations/'.$r->id.'/invite-link')->assertForbidden();
    }

    public function test_join_is_signed_capacity_limited_and_repeat_visits_reuse_private_membership(): void
    {
        $this->withoutVite();
        $r = $this->reservation();
        $url = URL::temporarySignedRoute('invitations.join', now()->addDay(), ['reservation' => $r->id]);
        $this->get($url)->assertOk()->assertSee('You’re Invited!');
        $first = $this->post($url, ['name' => 'Alex'])->assertRedirect()->headers->get('Location');
        $guest = app(GuestInvitationService::class)->resolve(basename($first));
        $this->assertSame('Alex', $guest->name);
        $this->assertNotNull($guest->name_confirmed_at);
        $this->post($url, ['name' => 'Alex'])->assertRedirect($first);
        $this->get($url)->assertRedirect($first);
        $this->assertSame(2, $r->guests()->count());
        $this->flushSession();
        $second = $this->post($url, ['name' => 'Julia'])->assertRedirect()->headers->get('Location');
        $this->assertNotSame($first, $second);
        $this->flushSession();
        $this->post($url, ['name' => 'Extra'])->assertSessionHasErrors('name');
        $this->assertSame(3, $r->guests()->count());
        $this->post('/join/'.$r->id, ['name' => 'Unsigned'])->assertForbidden();
        $r->update(['status' => 'cancelled']);
        $this->get($url)->assertGone();
    }

    public function test_owner_can_send_cart_directly_to_restaurant_and_retry_without_duplicate_items(): void
    {
        $r = $this->reservation();
        $category = $r->restaurant->menuCategories()->create(['name' => 'Appetizers', 'slug' => 'appetizers']);
        $dish = $category->items()->create(['name' => 'Salad', 'price' => '17.00']);
        $body = ['items' => [['menu_item_id' => $dish->id, 'quantity' => 2]], 'send_to_restaurant' => true];
        $url = '/reservations/'.$r->id.'/owner-pre-order/submit';
        $this->actingAs($r->owner)->postJson($url, $body, ['If-Match' => '"0"'])->assertOk()->assertJsonPath('order.status', 'submitted');
        $this->postJson($url, $body, ['If-Match' => '"0"'])->assertOk()->assertJsonPath('selections.subtotal', '34.00');
        $this->assertSame(1, $r->preOrder->items()->count());
        $this->assertDatabaseHas('reservation_events', ['reservation_id' => $r->id, 'audience' => 'restaurant', 'kind' => 'customer_submitted']);
    }
}
