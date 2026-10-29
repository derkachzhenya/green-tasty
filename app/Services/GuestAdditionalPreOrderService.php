<?php

namespace App\Services;

use App\Models\GuestPreOrderRound;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @phpstan-import-type SelectionSummary from GuestDishSelectionService */
class GuestAdditionalPreOrderService
{
    public function __construct(private GuestInvitationService $invitations) {}

    public function available(): bool
    {
        return Schema::hasTable('guest_pre_order_rounds');
    }

    public function draft(ReservationGuest $guest): ?GuestPreOrderRound
    {
        if (! $this->available()) {
            return null;
        }

        return GuestPreOrderRound::query()->where('reservation_guest_id', $guest->id)->whereNull('submitted_at')
            ->when(Schema::hasColumn('guest_pre_order_rounds', 'cancelled_at'), fn ($query) => $query->whereNull('cancelled_at'))->first();
    }

    public function ensureOpen(Reservation $reservation): void
    {
        PreOrderTiming::ensureOpen($reservation);
        $order = $reservation->preOrder()->lockForUpdate()->first();
        if (! ReservationCardState::acceptsPreOrder($reservation) || ($order !== null && ($order->status !== 'draft' || $order->submitted_at !== null))) {
            throw new ConflictHttpException('This reservation is no longer accepting pre-orders.');
        }
    }

    public function start(#[SensitiveParameter] string $token, int $expectedVersion): bool
    {
        if (! $this->available()) {
            throw new ConflictHttpException('New pre-orders are being prepared. Refresh shortly.');
        }

        return DB::transaction(function () use ($token, $expectedVersion): bool {
            $initial = $this->invitations->resolve($token);
            if ($initial === null) {
                return false;
            }
            $reservation = Reservation::query()->whereKey($initial->reservation_id)->lockForUpdate()->firstOrFail();
            $guest = $this->invitations->resolve($token, lockForUpdate: true);
            if ($guest === null || $guest->reservation_id !== $reservation->id) {
                return false;
            }
            $this->ensureOpen($reservation);
            if ($guest->pre_order_submitted_at === null || $guest->name_confirmed_at === null) {
                throw new ConflictHttpException('Confirm your current pre-order before starting another.');
            }
            // A retried start returns the existing draft, never a second empty cart.
            if ($this->draft($guest) !== null) {
                return true;
            }
            $this->match($guest, $expectedVersion);
            $number = (int) GuestPreOrderRound::query()->where('reservation_guest_id', $guest->id)->max('number') + 1;
            GuestPreOrderRound::query()->create(['reservation_guest_id' => $guest->id, 'number' => $number, 'items' => []]);
            $guest->increment('selection_version');

            return true;
        }, attempts: 3);
    }

    /**
     * Caller holds reservation and guest locks, in that order.
     *
     * @param  list<array{menu_item_id: int, quantity: int}>  $selections
     * @return SelectionSummary
     */
    public function replaceLocked(Reservation $reservation, ReservationGuest $guest, GuestPreOrderRound $round, array $selections, int $expectedVersion): array
    {
        $this->ensureOpen($reservation);
        $this->match($guest, $expectedVersion);
        $ids = array_column($selections, 'menu_item_id');
        $categories = MenuCategory::query()->where('restaurant_id', $reservation->restaurant_id)->where('is_active', true)->orderBy('id')->sharedLock()->pluck('id');
        $dishes = MenuItem::query()->whereIn('id', $ids)->whereIn('menu_category_id', $categories)->where('is_available', true)->orderBy('id')->sharedLock()->get()->keyBy('id');
        if ($dishes->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'One or more dishes are not available for this reservation.']);
        }
        $old = collect($round->items)->keyBy('menu_item_id');
        $quantities = array_column($selections, 'quantity', 'menu_item_id');
        $items = [];
        foreach ($dishes as $dish) {
            $items[] = ['menu_item_id' => $dish->id, 'name' => $dish->name, 'quantity' => $quantities[$dish->id], 'unit_price' => $old->get($dish->id)['unit_price'] ?? $dish->price];
        }
        // MySQL JSON reorders object keys; compare the same field order so an
        // unchanged cart does not gain a spurious version during confirmation.
        $previous = array_map(fn (array $item): array => [
            'menu_item_id' => $item['menu_item_id'], 'name' => $item['name'],
            'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'],
        ], $round->items);
        if ($items !== $previous) {
            $round->items = $items;
            $round->save();
            $guest->increment('selection_version');
        }

        return $this->summary($round);
    }

    /** @param list<array{menu_item_id: int, quantity: int}> $selections */
    public function submitLocked(Reservation $reservation, ReservationGuest $guest, GuestPreOrderRound $round, array $selections, int $expectedVersion): void
    {
        if ($selections === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one dish.']);
        }
        $this->replaceLocked($reservation, $guest, $round, $selections, $expectedVersion);
        $order = $reservation->preOrder()->firstOrFail();
        foreach ($round->items as $item) {
            $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $item['menu_item_id'], 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'], 'selection_round' => $round->number]);
        }
        $round->submitted_at = now();
        $round->save();
        $guest->forceFill(['pre_order_submitted_at' => now(), 'selection_version' => $guest->selection_version + 1])->save();
    }

    /** @return SelectionSummary */
    public function summary(GuestPreOrderRound $round): array
    {
        $items = [];
        $total = BigDecimal::zero();
        $count = 0;
        foreach ($round->items as $item) {
            $subtotal = BigDecimal::of($item['unit_price'])->multipliedBy($item['quantity']);
            $items[] = [...$item, 'subtotal' => (string) $subtotal->toScale(2)];
            $count += $item['quantity'];
            $total = $total->plus($subtotal);
        }

        return ['items' => $items, 'total_quantity' => $count, 'subtotal' => (string) $total->toScale(2)];
    }

    private function match(ReservationGuest $guest, int $version): void
    {
        if ($guest->selection_version !== $version) {
            throw new ConflictHttpException('Your cart changed in another tab. Refresh before saving.');
        }
    }
}
