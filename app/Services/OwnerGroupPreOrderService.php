<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OwnerGroupPreOrderService
{
    public function __construct(private ReservationDashboardService $dashboard, private GroupPreOrderSubmissionService $submission) {}

    /** @return array<string, mixed> */
    public function view(Reservation $reservation, User $owner): array
    {
        return DB::transaction(function () use ($reservation, $owner): array {
            $locked = $this->lock($reservation, $owner);

            return $this->snapshot($locked);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function quantity(Reservation $reservation, User $owner, int $itemId, int $quantity, string $version): array
    {
        return DB::transaction(function () use ($reservation, $owner, $itemId, $quantity, $version): array {
            $locked = $this->lock($reservation, $owner);
            Gate::forUser($owner)->authorize('update', $locked);
            $this->match($locked, $version);
            $this->editable($locked);
            $order = $locked->preOrder()->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($itemId)->whereRelation('guest', 'reservation_id', $locked->id)->firstOrFail();
            $guest = $locked->guests()->whereKey($item->reservation_guest_id)->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            $this->guestEditable($locked, $guest);
            if ($item->superseded_at !== null) {
                throw new ConflictHttpException('This item was replaced. Refresh the order.');
            }
            if ($quantity > 0) {
                // Match the existing category -> dish lock order and stabilize availability.
                $category = MenuCategory::query()->whereHas('items', fn ($query) => $query->whereKey($item->menu_item_id))->sharedLock()->first();
                $dish = $item->menuItem()->sharedLock()->firstOrFail();
                if (! $dish->is_available || ! $category?->is_active || $category->restaurant_id !== $locked->restaurant_id || $dish->menu_category_id !== $category->id) {
                    throw ValidationException::withMessages(['quantity' => 'This dish is no longer available. Remove it or refresh the order.']);
                }
            }
            if ($quantity !== $item->quantity) {
                if ($order->status === 'submitted' || $guest->pre_order_submitted_at !== null) {
                    app(CustomerPreOrderRevisionService::class)->quantity($locked, $owner, $item, $quantity);
                } elseif ($quantity === 0) {
                    $item->delete();
                } else {
                    // Preserve the saved price and notes; only an absolute quantity can be changed.
                    $item->fill(['quantity' => $quantity])->save();
                }
                if ($order->status !== 'submitted' && $guest->pre_order_submitted_at === null) {
                    $guest->increment('selection_version');
                }
                if ($order->status !== 'submitted' && $guest->user_id === $locked->owner_user_id) {
                    $guest->forceFill(['pre_order_submitted_at' => null])->save();
                }
            }

            return $this->snapshot($locked);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function removeGuest(Reservation $reservation, User $owner, int $guestId, string $version): array
    {
        return DB::transaction(function () use ($reservation, $owner, $guestId, $version): array {
            $locked = $this->lock($reservation, $owner);
            Gate::forUser($owner)->authorize('update', $locked);
            $this->match($locked, $version);
            $this->editable($locked);
            $guest = $locked->guests()->whereKey($guestId)->lockForUpdate()->firstOrFail();
            if ($guest->user_id === $locked->owner_user_id) {
                throw ValidationException::withMessages(['guest' => 'The reservation owner cannot be removed.']);
            }
            $this->guestEditable($locked, $guest);
            $order = $locked->preOrder()->lockForUpdate()->first();
            if ($order?->status === 'submitted') {
                app(CustomerPreOrderRevisionService::class)->removeGuest($locked, $owner, $guest);
            } elseif ($guest->pre_order_submitted_at !== null) {
                $order?->items()->where('reservation_guest_id', $guest->id)->whereNull('superseded_at')->update(['superseded_at' => now()]);
            } else {
                $order?->items()->where('reservation_guest_id', $guest->id)->delete();
            }
            // Remove active membership, retaining the reservation relationship and RSVP history.
            $guest->forceFill(['removed_at' => now(), 'invitation_token' => null, 'invitation_revoked_at' => now(), 'selection_version' => $guest->selection_version + 1])->save();

            return $this->snapshot($locked);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function submit(Reservation $reservation, User $owner, string $version): array
    {
        return DB::transaction(function () use ($reservation, $owner, $version): array {
            $locked = $this->lock($reservation, $owner);
            $order = $locked->preOrder()->lockForUpdate()->first();
            // Preserve the existing idempotent retry contract after a lost successful response.
            if ($order?->status !== 'submitted' || $order->submitted_at === null) {
                $this->match($locked, $version);
                $this->editable($locked);
            }
            $this->submission->submit($locked, $owner);

            return $this->snapshot($locked);
        }, attempts: 3);
    }

    private function lock(Reservation $reservation, User $owner): Reservation
    {
        $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($owner)->authorize('viewSummary', $locked);

        return $locked;
    }

    public function acknowledge(Reservation $reservation, User $owner, string $version): void
    {
        DB::transaction(function () use ($reservation, $owner, $version): void {
            $locked = $this->lock($reservation, $owner);
            $this->match($locked, $version);
            // Reading a group never mutates the order or acknowledges a later racing event.
            DB::table('reservations')->where('id', $locked->id)->update(['pre_order_seen_event_id' => PreOrderNotifications::latest($locked)]);
        }, attempts: 3);
    }

    private function editable(Reservation $reservation): void
    {
        $order = $reservation->preOrder()->first();
        if (! ReservationCardState::acceptsPreOrder($reservation) || ($order !== null && ! (($order->status === 'draft' && $order->submitted_at === null) || ($order->status === 'submitted' && $order->submitted_at !== null)))) {
            throw new ConflictHttpException('This group pre-order is read-only.');
        }
    }

    private function guestEditable(Reservation $reservation, ReservationGuest $guest): void
    {
        if ($guest->removed_at !== null || ($guest->user_id !== $reservation->owner_user_id && $guest->invitation_revoked_at !== null)) {
            throw new ConflictHttpException('This guest was removed or their invitation was revoked.');
        }
    }

    private function match(Reservation $reservation, string $expected): void
    {
        if (! hash_equals($this->version($reservation), $expected)) {
            throw new ConflictHttpException('The group pre-order changed. Refresh and review it before continuing.');
        }
    }

    private function version(Reservation $reservation): string
    {
        $order = $reservation->preOrder()->first();
        $state = [
            PreOrderNotifications::latest($reservation),
            $reservation->only(['id', 'owner_user_id', 'restaurant_id', 'reserved_at', 'duration_minutes', 'guest_count', 'status', 'updated_at']),
            $order?->only(['id', 'status', 'submitted_at', 'updated_at', 'review_revision', 'reviewed_revision', 'review_submitted_at']),
            $reservation->guests()->orderBy('id')->get(['id', 'user_id', 'name', 'selection_version', 'pre_order_submitted_at', 'invitation_revoked_at', 'invitation_expires_at', 'removed_at'])->toArray(),
            $order?->items()->orderBy('id')->get(['id', 'reservation_guest_id', 'menu_item_id', 'quantity', 'unit_price', 'notes', 'updated_at', 'restaurant_decision', 'review_published_at', 'superseded_at'])->toArray(),
        ];

        return '"'.hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)).'"';
    }

    /** @return array<string, mixed> */
    private function snapshot(Reservation $reservation): array
    {
        $data = $this->dashboard->groupOrder($reservation);
        $order = $reservation->preOrder()->first();
        $editable = ReservationCardState::acceptsPreOrder($reservation) && ($order === null || ($order->status === 'draft' && $order->submitted_at === null) || ($order->status === 'submitted' && $order->submitted_at !== null));
        $guests = $reservation->guests()->active()->orderBy('id')->get();
        $items = $order?->items()->whereNull('superseded_at')->whereRelation('guest', 'reservation_id', $reservation->id)->with('menuItem')->orderBy('id')->get();
        $participants = [];
        foreach ($guests as $guest) {
            $own = $guest->user_id === $reservation->owner_user_id;
            $saved = collect($data['summary']['guests'])->firstWhere('reservation_guest_id', $guest->id);
            $source = $items?->where('reservation_guest_id', $guest->id)->filter(fn (PreOrderItem $item): bool => $item->menuItem !== null)->values();
            $lines = [];
            foreach ($saved['items'] ?? [] as $index => $line) {
                $row = $source?->get($index);
                if ($row !== null) {
                    $lines[] = [...$line, 'id' => $row->id, 'notes' => $row->notes, 'decision' => $row->review_published_at === null ? 'pending' : $row->restaurant_decision];
                }
            }
            $participantEditable = $editable && ($own || $guest->invitation_revoked_at === null);
            $participants[] = [
                'id' => $guest->id, 'name' => $guest->name, 'is_owner' => $own,
                'confirmed' => $guest->pre_order_submitted_at !== null,
                'revoked' => $guest->invitation_revoked_at !== null,
                'editable' => $participantEditable,
                'can_remove' => $participantEditable && ! $own,
                'items' => $lines, 'subtotal' => $saved['subtotal'] ?? '0.00',
            ];
        }

        return [
            'timing' => PreOrderTiming::state($reservation),
            'notification_event_id' => PreOrderNotifications::latest($reservation),
            'reservation_id' => $reservation->id, 'version' => $this->version($reservation),
            'status' => $order?->status, 'submitted_at' => $order?->submitted_at?->toIso8601String(),
            'editable' => $editable, 'can_submit' => $editable && Gate::allows('submitPreOrder', $reservation) && $order?->status === 'draft' && $data['summary']['total_quantity'] > 0,
            'active_participants' => $guests->count(),
            'removed_participants' => $reservation->guests()->whereNotNull('removed_at')->count(),
            'participants' => $participants, 'summary' => $data['summary'],
            ...($order?->status === 'submitted' && $order->submitted_at !== null ? ['restaurant_review' => app(RestaurantPreOrderReviewService::class)->ownerState($reservation)] : []),
        ];
    }
}
