<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PreOrder;
use App\Models\PreOrderItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Restaurant review is independent from customer submission and its T−30 lock. */
class RestaurantPreOrderReviewService
{
    /**
     * @return array<string, mixed>
     */
    public function view(Restaurant $restaurant, Reservation $reservation, User $staff): array
    {
        return DB::transaction(fn (): array => $this->snapshot($this->staffLock($restaurant, $reservation, $staff)), attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(Restaurant $restaurant, Reservation $reservation, User $staff, int $itemId, string $decision, string $version): array
    {
        if (! in_array($decision, ['confirmed', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose confirmed or rejected.']);
        }

        return DB::transaction(function () use ($restaurant, $reservation, $staff, $itemId, $decision, $version): array {
            $locked = $this->staffLock($restaurant, $reservation, $staff);
            $order = $this->order($locked);
            $item = $order->items()->whereKey($itemId)->whereRelation('guest', 'reservation_id', $locked->id)->lockForUpdate()->firstOrFail();
            $this->reviewable($locked, $order);
            // A duplicate is read-only; it can never overwrite a newer opposing decision.
            if ($item->restaurant_decision === $decision && $item->superseded_at === null) {
                return $this->snapshot($locked);
            }
            $this->match($locked, $version);
            if ($item->review_published_at !== null || $item->superseded_at !== null || (int) $order->reviewed_revision === (int) $order->review_revision) {
                throw new ConflictHttpException('This item review is already published.');
            }
            $item->forceFill(['restaurant_decision' => $decision])->save();

            return $this->snapshot($locked);
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(Restaurant $restaurant, Reservation $reservation, User $staff, string $version): array
    {
        return DB::transaction(function () use ($restaurant, $reservation, $staff, $version): array {
            $locked = $this->staffLock($restaurant, $reservation, $staff);
            $order = $this->order($locked);
            if ((int) $order->reviewed_revision === (int) $order->review_revision) {
                return $this->snapshot($locked);
            }
            $this->match($locked, $version);
            $this->reviewable($locked, $order);
            $items = $order->items()->whereNull('superseded_at')->with(['guest', 'menuItem.category'])->lockForUpdate()->get();
            foreach ($items as $item) {
                if ($item->guest?->reservation_id !== $locked->id || $item->guest->removed_at !== null || $item->menuItem?->category?->restaurant_id !== $locked->restaurant_id) {
                    throw ValidationException::withMessages(['review' => 'The review contains an invalid participant or restaurant item.']);
                }
            }
            if ($items->isEmpty() || $items->contains(fn (PreOrderItem $item): bool => ! in_array($item->restaurant_decision, ['confirmed', 'rejected'], true))) {
                throw ValidationException::withMessages(['review' => 'Decide every active item before submitting.']);
            }
            foreach ($items as $item) {
                if ($item->review_published_at === null) {
                    $item->forceFill(['review_published_at' => now()])->save();
                }
            }
            $order->forceFill(['reviewed_revision' => $order->review_revision, 'review_submitted_at' => now()])->save();
            DB::table('pre_order_review_rounds')->insert([
                'pre_order_id' => $order->id, 'revision' => $order->review_revision, 'reviewer_user_id' => $staff->id,
                'snapshot' => json_encode($this->snapshot($locked), JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $this->event($locked, 'restaurant_review_submitted', (int) $order->review_revision, 'owner');
            if ($items->contains('restaurant_decision', 'rejected')) {
                $this->event($locked, 'items_rejected', (int) $order->review_revision, 'owner');
            }

            return $this->snapshot($locked);
        }, attempts: 3);
    }

    /** Explicit append-only replacement of one published rejection; all old prices/rows remain.
     * @return array<string, mixed>
     */
    public function correct(Reservation $reservation, User $owner, int $itemId, int $dishId, int $quantity, string $version): array
    {
        return DB::transaction(function () use ($reservation, $owner, $itemId, $dishId, $quantity, $version): array {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('update', $locked);
            $this->match($locked, $version);
            PreOrderTiming::ensureOpen($locked);
            if (! ReservationCardState::acceptsPreOrder($locked)) {
                throw new ConflictHttpException('This reservation no longer accepts corrections.');
            }
            $order = $this->order($locked);
            $item = $order->items()->whereKey($itemId)->whereRelation('guest', 'reservation_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($item->restaurant_decision !== 'rejected' || $item->review_published_at === null || $item->superseded_at !== null) {
                throw new ConflictHttpException('Only a published, unreplaced rejection can be corrected.');
            }
            $guest = $locked->guests()->active()->whereKey($item->reservation_guest_id)->lockForUpdate()->firstOrFail();
            $categoryIds = MenuCategory::query()->where('restaurant_id', $locked->restaurant_id)->where('is_active', true)->sharedLock()->pluck('id');
            $dish = MenuItem::query()->whereKey($dishId)->where('is_available', true)->whereIn('menu_category_id', $categoryIds)->sharedLock()->first();
            if ($dish === null || $quantity < 1 || $quantity > 99 || $order->items()->where('reservation_guest_id', $guest->id)->where('menu_item_id', $dishId)->exists()) {
                throw ValidationException::withMessages(['replacement' => 'Choose an available dish not already recorded for this participant and a quantity from 1 to 99.']);
            }
            // Never edit or delete a submitted row, its quantity, notes or saved unit price.
            $item->forceFill(['superseded_at' => now()])->save();
            $order->items()->create(['reservation_guest_id' => $guest->id, 'menu_item_id' => $dish->id, 'quantity' => $quantity, 'unit_price' => $dish->price]);
            $order->forceFill(['review_revision' => (int) $order->review_revision + 1])->save();
            $guest->increment('selection_version');
            $this->event($locked, 'customer_updated', (int) $order->review_revision, 'restaurant');

            return $this->ownerState($locked) ?? throw new ConflictHttpException('The submitted order is unavailable.');
        }, attempts: 3);
    }

    public function event(Reservation $reservation, string $kind, int $revision, string $audience): void
    {
        DB::table('reservation_events')->insertOrIgnore([
            'reservation_id' => $reservation->id, 'restaurant_id' => $reservation->restaurant_id,
            'recipient_user_id' => $audience === 'owner' ? $reservation->owner_user_id : null,
            'audience' => $audience, 'kind' => $kind, 'revision' => $revision,
            'event_key' => hash('sha256', "$reservation->id:$kind:$revision:$audience"), 'created_at' => now(),
        ]);
    }

    /** Called only by authorized owner read paths.
     * @return array<string, mixed>|null
     */
    public function ownerState(Reservation $reservation): ?array
    {
        return DB::transaction(function () use ($reservation): ?array {
            $reservation = Reservation::query()->whereKey($reservation->id)->sharedLock()->firstOrFail();
            $order = $reservation->preOrder()->first();
            if ($order?->status !== 'submitted' || $order->submitted_at === null) {
                return null;
            }
            $data = $this->snapshot($reservation);
            foreach ($data['participants'] as &$participant) {
                foreach ($participant['items'] as &$item) {
                    // Waiter drafts are never represented to the owner as published decisions.
                    if (! $item['published']) {
                        $item['decision'] = 'pending';
                    }
                }
                unset($item);
            }
            unset($participant);
            $data['can_correct'] = ReservationCardState::acceptsPreOrder($reservation);
            $data['catalog'] = MenuItem::query()->where('is_available', true)
                ->whereHas('category', fn ($q) => $q->where('restaurant_id', $reservation->restaurant_id)->where('is_active', true))
                ->orderBy('name')->get(['id', 'name', 'price'])->toArray();

            return $data;
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Reservation $reservation): array
    {
        $order = $this->order($reservation);
        $participants = [];
        $totals = ['requested' => BigDecimal::zero(), 'confirmed' => BigDecimal::zero(), 'rejected' => BigDecimal::zero(), 'pending' => BigDecimal::zero()];
        foreach ($reservation->guests()->active()->orderBy('id')->get() as $guest) {
            $lines = [];
            foreach ($order->items()->where('reservation_guest_id', $guest->id)->with('menuItem')->orderBy('id')->get() as $item) {
                $amount = BigDecimal::of($item->unit_price)->multipliedBy($item->quantity);
                if ($item->superseded_at === null) {
                    $totals['requested'] = $totals['requested']->plus($amount);
                    $bucket = $item->review_published_at === null ? 'pending' : $item->restaurant_decision;
                    $totals[$bucket] = $totals[$bucket]->plus($amount);
                }
                $lines[] = ['id' => $item->id, 'menu_item_id' => $item->menu_item_id, 'name' => $item->menuItem->name ?? 'Unavailable dish',
                    'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'total' => (string) $amount->toScale(2), 'notes' => $item->notes,
                    'decision' => $item->restaurant_decision, 'published' => $item->review_published_at !== null, 'superseded' => $item->superseded_at !== null];
            }
            $participants[] = ['id' => $guest->id, 'name' => $guest->name, 'is_owner' => $guest->user_id === $reservation->owner_user_id, 'items' => $lines];
        }
        $unreviewed = (int) $order->review_revision !== (int) $order->reviewed_revision;
        $editable = $unreviewed && in_array(ReservationCardState::phase($reservation), ['upcoming', 'in_progress'], true);

        return ['version' => $this->version($reservation), 'revision' => (int) $order->review_revision,
            'status' => $unreviewed ? ((int) $order->review_revision > 1 ? 'Updated Pre-Order' : 'Created by Customer') : 'Confirmed by Restaurant',
            'editable' => $editable, 'can_submit' => $editable && $order->items()->whereNull('superseded_at')->exists()
                && ! $order->items()->whereNull('superseded_at')->where('restaurant_decision', 'pending')->exists(),
            'participants' => $participants, 'totals' => array_map(fn (BigDecimal $amount): string => (string) $amount->toScale(2), $totals),
            'events' => DB::table('reservation_events')->where('reservation_id', $reservation->id)->orderByDesc('id')->limit(10)->get(['kind', 'revision', 'audience'])->toArray()];
    }

    public function version(Reservation $reservation): string
    {
        $order = $reservation->preOrder()->first();

        return '"'.hash('sha256', json_encode([$reservation->only(['id', 'status', 'restaurant_table_id', 'reserved_at', 'guest_count', 'updated_at']),
            $order?->getAttributes(), $order?->items()->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR)).'"';
    }

    public function match(Reservation $reservation, string $version): void
    {
        if (! hash_equals($this->version($reservation), $version)) {
            throw new ConflictHttpException('The reservation or review changed. Refresh before continuing.');
        }
    }

    private function staffLock(Restaurant $restaurant, Reservation $reservation, User $staff): Reservation
    {
        Gate::forUser($staff)->authorize('staff', $restaurant);
        $locked = $restaurant->reservations()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
        // Recheck membership inside the transaction; revocation must deny the next operation.
        Gate::forUser($staff)->authorize('staff', $restaurant);

        return $locked;
    }

    private function order(Reservation $reservation): PreOrder
    {
        $order = $reservation->preOrder()->first();
        if ($order?->status !== 'submitted' || $order->submitted_at === null) {
            throw new ConflictHttpException('Customer group submission is required before restaurant review.');
        }

        return $order;
    }

    private function reviewable(Reservation $reservation, PreOrder $order): void
    {
        if (! in_array(ReservationCardState::phase($reservation), ['upcoming', 'in_progress'], true)) {
            throw new ConflictHttpException('This reservation review is read-only.');
        }
    }
}
