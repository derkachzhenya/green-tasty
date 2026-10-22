<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupPreOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_participants_can_have_items_in_one_group_order(): void
    {
        $owner = User::factory()->create();

        $restaurant = Restaurant::create([
            'name' => 'Green Tasty Test',
            'slug' => 'green-tasty-test',
            'address' => 'Test Address',
        ]);

        $category = $restaurant->menuCategories()->create([
            'name' => 'Main Courses',
            'slug' => 'main-courses',
        ]);

        $dish = $category->items()->create([
            'name' => 'Vegetable Bowl',
            'price' => '12.50',
        ]);

        $reservation = $restaurant->reservations()->create([
            'owner_user_id' => $owner->id,
            'code' => 'GT-TEST-001',
            'reserved_at' => now()->addDay(),
            'guest_count' => 2,
        ]);

        $ownerParticipant = $reservation->guests()->create([
            'user_id' => $owner->id,
            'name' => $owner->name,
        ]);

        $guest = $reservation->guests()->create([
            'name' => 'Alice',
        ]);

        $preOrder = $reservation->preOrder()->create([
            'status' => 'draft',
        ]);

        foreach ([
            [$ownerParticipant->id, 1],
            [$guest->id, 2],
        ] as [$guestId, $quantity]) {
            $preOrder->items()->create([
                'reservation_guest_id' => $guestId,
                'menu_item_id' => $dish->id,
                'quantity' => $quantity,
                'unit_price' => '12.50',
            ]);
        }

        $items = $preOrder->items()->with('guest')->get();

        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(
            [$owner->name, 'Alice'],
            $items->pluck('guest.name')->all()
        );
        $this->assertSame(3, $items->sum('quantity'));
        $this->assertSame(1, $reservation->preOrder()->count());
    }
}
