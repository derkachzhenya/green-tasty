<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PreOrder;
use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Called inside the reservation-first owner transaction. Submitted rows are never rewritten. */
class CustomerPreOrderRevisionService
{
    public function editable(Reservation $reservation): void
    {
        if (! ReservationCardState::acceptsPreOrder($reservation)) {
            throw new ConflictHttpException('Pre-order changes close 30 minutes before the reservation.');
        }
    }

    public function quantity(Reservation $reservation, User $owner, PreOrderItem $item, int $quantity): void
    {
        $this->editable($reservation);
        if ($item->superseded_at !== null) {
            throw new ConflictHttpException('This item was replaced. Refresh the group pre-order.');
        }
        if ($item->quantity === $quantity) {
            return;
        }
        $order = $item->preOrder;
        if ($order->status === 'submitted') {
            $this->record($order, $owner, 'before_change');
        }
        $this->supersede($item);
        if ($quantity > 0) {
            $this->append($order, $item->guest, $item->menu_item_id, $quantity, $item->unit_price, $item->notes);
        }
        $item->guest->increment('selection_version');
        if ($order->status === 'submitted') {
            $this->publish($reservation, $order, $owner, 'quantity_changed');
        }
    }

    /** @param list<array{menu_item_id: int, quantity: int}> $selections */
    public function replaceOwner(Reservation $reservation, User $owner, ReservationGuest $guest, array $selections): void
    {
        $this->editable($reservation);
        $order = $reservation->preOrder()->lockForUpdate()->firstOrFail();
        $ids = array_column($selections, 'menu_item_id');
        $categoryIds = MenuCategory::query()->where('restaurant_id', $reservation->restaurant_id)->where('is_active', true)->orderBy('id')->sharedLock()->pluck('id');
        $dishes = MenuItem::query()->whereIn('id', $ids)->whereIn('menu_category_id', $categoryIds)->where('is_available', true)->orderBy('id')->sharedLock()->get()->keyBy('id');
        if ($dishes->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'One or more dishes are not available for this reservation.']);
        }
        $existing = $order->items()->where('reservation_guest_id', $guest->id)->whereNull('superseded_at')->orderBy('id')->lockForUpdate()->get()->keyBy('menu_item_id');
        $this->record($order, $owner, 'before_change');
        foreach ($existing as $item) {
            if (! in_array($item->menu_item_id, $ids, true)) {
                $this->supersede($item);
            }
        }
        foreach ($selections as $selection) {
            $item = $existing->get($selection['menu_item_id']);
            if ($item !== null && $item->quantity === $selection['quantity']) {
                continue;
            }
            if ($item !== null) {
                $this->supersede($item);
            }
            $price = $item !== null ? $item->unit_price : $dishes->get($selection['menu_item_id'])->price;
            $this->append($order, $guest, $selection['menu_item_id'], $selection['quantity'], $price, $item?->notes);
        }
        $guest->forceFill(['selection_version' => $guest->selection_version + 1, 'pre_order_submitted_at' => $selections === [] ? null : now()])->save();
        $this->publish($reservation, $order, $owner, 'owner_selection_changed');
    }

    public function removeGuest(Reservation $reservation, User $owner, ReservationGuest $guest): void
    {
        $this->editable($reservation);
        $order = $reservation->preOrder()->lockForUpdate()->firstOrFail();
        $this->record($order, $owner, 'before_change');
        foreach ($order->items()->where('reservation_guest_id', $guest->id)->whereNull('superseded_at')->lockForUpdate()->get() as $item) {
            $this->supersede($item);
        }
        $this->publish($reservation, $order, $owner, 'guest_removed');
    }

    public function record(PreOrder $order, User $owner, string $reason): void
    {
        DB::table('pre_order_customer_revisions')->insertOrIgnore([
            'pre_order_id' => $order->id, 'revision' => $order->review_revision, 'actor_user_id' => $owner->id,
            'reason' => $reason, 'snapshot' => json_encode(['order' => $order->getAttributes(), 'items' => $order->items()->orderBy('id')->get()->map->getRawOriginal()->all()], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    private function supersede(PreOrderItem $item): void
    {
        $item->forceFill(['superseded_at' => now()])->save();
    }

    private function append(PreOrder $order, ReservationGuest $guest, int $dishId, int $quantity, string $price, ?string $notes): void
    {
        $round = (int) $order->items()->where('reservation_guest_id', $guest->id)->max('selection_round') + 1;
        $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dishId, 'quantity' => $quantity, 'unit_price' => $price, 'notes' => $notes, 'selection_round' => $round]);
    }

    private function publish(Reservation $reservation, PreOrder $order, User $owner, string $reason): void
    {
        $order->forceFill(['review_revision' => (int) $order->review_revision + 1])->save();
        $this->record($order, $owner, $reason);
        app(RestaurantPreOrderReviewService::class)->event($reservation, 'customer_updated', (int) $order->review_revision, 'restaurant');
    }
}
