<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @phpstan-import-type GuestMenu from GuestMenuService */
class OwnerPreOrderService
{
    public function __construct(private GuestDishSelectionService $selections, private GuestMenuService $menus) {}

    /**
     * Apply an owner draft and its confirmation atomically. No intermediate cart write.
     *
     * @param  list<array{menu_item_id: int, quantity: int}>  $items
     * @return GuestMenu
     */
    public function submit(Reservation $reservation, User $owner, array $items, int $version): array
    {
        return DB::transaction(function () use ($reservation, $owner, $items, $version): array {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('view', $locked);
            $guest = $locked->guests()->where('user_id', $owner->id)->lockForUpdate()->firstOrFail();
            $saved = $this->selections->forGuest($guest);
            $quantities = array_column($items, 'quantity', 'menu_item_id');
            $previous = array_column($saved['items'], 'quantity', 'menu_item_id');
            ksort($quantities);
            ksort($previous);
            // Lost-success retries are read-only, including after the cutoff or group submission.
            if ($guest->pre_order_submitted_at !== null && $quantities === $previous && $version <= $guest->selection_version) {
                return $this->menus->forOwner($locked, $owner);
            }
            PreOrderTiming::ensureOpen($locked);
            if (! ReservationCardState::acceptsPreOrder($locked)) {
                throw new ConflictHttpException('This reservation is no longer accepting pre-orders.');
            }
            $this->selections->replaceForOwner($locked, $owner, $items, $version);
            $guest->refresh()->forceFill(['pre_order_submitted_at' => $items === [] ? null : now()])->save();

            return $this->menus->forOwner($locked, $owner);
        }, attempts: 3);
    }
}
