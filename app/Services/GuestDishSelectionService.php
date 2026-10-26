<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @phpstan-type Selection array{menu_item_id: int, quantity: int}
 * @phpstan-type SelectionItem array{menu_item_id: int, name: string, quantity: int, unit_price: string, subtotal: string}
 * @phpstan-type SelectionSummary array{items: list<SelectionItem>, total_quantity: int, subtotal: string}
 */
class GuestDishSelectionService
{
    public function __construct(private GuestInvitationService $invitations) {}

    /** @return SelectionSummary|null */
    public function view(#[SensitiveParameter] string $token): ?array
    {
        $guest = $this->invitations->resolve($token);

        if ($guest === null) {
            return null;
        }

        $items = $guest->preOrderItems()
            ->whereRelation('preOrder', 'reservation_id', $guest->reservation_id)
            ->with('menuItem')
            ->orderBy('menu_item_id')
            ->get();

        return $this->summary($items);
    }

    /**
     * @param  list<Selection>  $selections  Validated, distinct menu IDs and positive quantities.
     * @return SelectionSummary|null Null when invitation access is no longer valid.
     */
    public function replace(#[SensitiveParameter] string $token, array $selections, int $expectedVersion): ?array
    {
        return DB::transaction(function () use ($token, $selections, $expectedVersion): ?array {
            $initialGuest = $this->invitations->resolve($token);

            if ($initialGuest === null) {
                return null;
            }

            // Serialize first-order creation and all guest replacements for this reservation.
            $reservation = Reservation::query()->whereKey($initialGuest->reservation_id)->lockForUpdate()->firstOrFail();
            $guest = $this->invitations->resolve($token, lockForUpdate: true);

            if ($guest === null || $guest->reservation_id !== $reservation->id) {
                return null;
            }

            return $this->replaceLocked($reservation, $guest, $selections, $expectedVersion);
        }, attempts: 3);
    }

    /**
     * @param  list<Selection>  $selections
     * @return SelectionSummary
     */
    public function replaceForOwner(Reservation $reservation, User $owner, array $selections, int $expectedVersion): array
    {
        return DB::transaction(function () use ($reservation, $owner, $selections, $expectedVersion): array {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('view', $locked);
            $guest = $locked->guests()->where('user_id', $owner->id)->lockForUpdate()->firstOrFail();
            $result = $this->replaceLocked($locked, $guest, $selections, $expectedVersion, true);
            if ($guest->selection_version !== $expectedVersion) {
                $guest->forceFill(['pre_order_submitted_at' => null])->save();
            }

            return $result;
        }, attempts: 3);
    }

    /** @return SelectionSummary */
    public function forGuest(ReservationGuest $guest): array
    {
        return $this->summary($guest->preOrderItems()
            ->whereRelation('preOrder', 'reservation_id', $guest->reservation_id)
            ->with('menuItem')->orderBy('menu_item_id')->get());
    }

    /**
     * @param  list<Selection>  $selections
     * @return SelectionSummary
     */
    private function replaceLocked(Reservation $reservation, ReservationGuest $guest, array $selections, int $expectedVersion, bool $ownerEdit = false): array
    {
        PreOrderTiming::ensureOpen($reservation);
        $preOrder = $reservation->preOrder()->lockForUpdate()->first();

        if ((! $ownerEdit && $guest->pre_order_submitted_at !== null) || in_array($reservation->status, ['cancelled', 'completed'], true)
            || ($preOrder !== null && ($preOrder->status !== 'draft' || $preOrder->submitted_at !== null))) {
            throw new ConflictHttpException('The pre-order is no longer editable.');
        }

        if ($ownerEdit && ! ReservationCardState::acceptsPreOrder($reservation)) {
            throw new ConflictHttpException('This reservation is no longer accepting pre-orders.');
        }

        if ($guest->selection_version !== $expectedVersion) {
            throw new ConflictHttpException('Your cart changed in another tab. Refresh before saving.');
        }

        if ($selections === []) {
            if ($preOrder?->items()->where('reservation_guest_id', $guest->id)->delete()) {
                $guest->increment('selection_version');
            }

            return $this->summary([]);
        }

        $ids = array_column($selections, 'menu_item_id');
        sort($ids);
        $quantities = array_column($selections, 'quantity', 'menu_item_id');

        // Shared locks keep category availability and menu prices stable through the write.
        $categoryIds = MenuCategory::query()
            ->where('restaurant_id', $reservation->restaurant_id)
            ->where('is_active', true)
            ->orderBy('id')->sharedLock()->pluck('id');
        $dishes = MenuItem::query()
            ->whereIn('id', $ids)
            ->whereIn('menu_category_id', $categoryIds)
            ->where('is_available', true)
            ->orderBy('id')->sharedLock()->get()->keyBy('id');

        if ($dishes->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'One or more dishes are not available for this reservation.']);
        }

        $preOrder ??= $reservation->preOrder()->create(['status' => 'draft']);
        $existing = $preOrder->items()->where('reservation_guest_id', $guest->id)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('menu_item_id');
        $changed = $existing->count() !== count($selections);
        $preOrder->items()->where('reservation_guest_id', $guest->id)->whereNotIn('menu_item_id', $ids)->delete();
        $savedItems = [];

        // Stable ordering also makes a reordered retry return the same representation.
        foreach ($dishes as $dish) {
            $quantity = $quantities[$dish->id];
            $item = $existing->get($dish->id);

            if ($item === null) {
                $changed = true;
                $item = $preOrder->items()->create([
                    'reservation_guest_id' => $guest->id,
                    'menu_item_id' => $dish->id,
                    'quantity' => $quantity,
                    'unit_price' => $dish->price,
                ]);
            } elseif ($item->quantity !== $quantity) {
                $changed = true;
                $item->fill(['quantity' => $quantity])->save();
            }

            $savedItems[] = $item->setRelation('menuItem', $dish);
        }

        if ($changed) {
            $guest->increment('selection_version');
        }

        return $this->summary($savedItems);
    }

    /**
     * @param  iterable<PreOrderItem>  $items  Items with their menuItem relation already loaded.
     * @return SelectionSummary
     */
    private function summary(iterable $items): array
    {
        $summary = ['items' => [], 'total_quantity' => 0, 'subtotal' => '0.00'];
        $total = BigDecimal::zero();

        foreach ($items as $item) {
            $dish = $item->menuItem;

            if ($dish === null) {
                continue;
            }

            $subtotal = BigDecimal::of($item->unit_price)->multipliedBy($item->quantity);
            $summary['items'][] = [
                'menu_item_id' => $dish->id,
                'name' => $dish->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => (string) $subtotal->toScale(2),
            ];
            $summary['total_quantity'] += $item->quantity;
            $total = $total->plus($subtotal);
        }

        $summary['subtotal'] = (string) $total->toScale(2);

        return $summary;
    }
}
