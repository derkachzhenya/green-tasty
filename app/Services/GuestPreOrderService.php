<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationGuest;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @phpstan-import-type GuestMenu from GuestMenuService */
class GuestPreOrderService
{
    public function __construct(private GuestInvitationService $invitations, private GuestDishSelectionService $selections, private GuestMenuService $menus, private GuestAdditionalPreOrderService $additional) {}

    /** @return GuestMenu|null */
    public function start(#[SensitiveParameter] string $token, int $expectedVersion): ?array
    {
        return $this->additional->start($token, $expectedVersion) ? $this->menus->view($token) : null;
    }

    /** @return GuestMenu|null */
    public function identify(#[SensitiveParameter] string $token, string $name): ?array
    {
        return DB::transaction(function () use ($token, $name): ?array {
            $guest = $this->lockGuest($token);
            if ($guest === null) {
                return null;
            }
            $this->ensureEditable($guest);
            if ($guest->name_confirmed_at === null || $guest->name !== $name) {
                $guest->forceFill(['name' => $name, 'name_confirmed_at' => now()])->save();
            }

            return $this->menus->view($token);
        }, attempts: 3);
    }

    /**
     * Confirm only this guest's selections; group submission remains owner-only.
     *
     * @param  list<array{menu_item_id: int, quantity: int}>  $items
     * @return GuestMenu|null
     */
    public function submit(#[SensitiveParameter] string $token, array $items, int $expectedVersion): ?array
    {
        return DB::transaction(function () use ($token, $items, $expectedVersion): ?array {
            $guest = $this->lockGuest($token);
            if ($guest === null) {
                return null;
            }
            // A lost response or repeated click must never create another order or reopen one.
            if (($round = $this->additional->draft($guest)) !== null) {
                $this->additional->submitLocked($guest->reservation, $guest, $round, $items, $expectedVersion);
                PreOrderNotifications::guestSubmitted($guest->refresh());

                return $this->menus->view($token);
            }
            if ($guest->pre_order_submitted_at !== null) {
                return $this->menus->view($token);
            }
            $this->ensureEditable($guest);
            if ($guest->name_confirmed_at === null) {
                throw new ConflictHttpException('Enter your name before submitting your pre-order.');
            }
            if ($this->selections->replace($token, $items, $expectedVersion) === null) {
                return null;
            }
            $guest->forceFill(['pre_order_submitted_at' => now()])->save();
            PreOrderNotifications::guestSubmitted($guest);

            return $this->menus->view($token);
        }, attempts: 3);
    }

    private function lockGuest(#[SensitiveParameter] string $token): ?ReservationGuest
    {
        $initial = $this->invitations->resolve($token);
        if ($initial === null) {
            return null;
        }
        $reservation = Reservation::query()->whereKey($initial->reservation_id)->lockForUpdate()->firstOrFail();
        $guest = $this->invitations->resolve($token, lockForUpdate: true);
        if ($guest === null || $guest->reservation_id !== $reservation->id) {
            return null;
        }

        return $guest->setRelation('reservation', $reservation);
    }

    private function ensureEditable(ReservationGuest $guest): void
    {
        $reservation = $guest->reservation;
        if ($reservation !== null) {
            PreOrderTiming::ensureOpen($reservation);
        }
        $order = $reservation?->preOrder()->lockForUpdate()->first();
        if ($reservation === null || in_array($reservation->status, ['cancelled', 'completed'], true)
            || $guest->pre_order_submitted_at !== null
            || ($order !== null && ($order->status !== 'draft' || $order->submitted_at !== null))) {
            throw new ConflictHttpException('This pre-order is no longer editable.');
        }
    }
}
