<?php

namespace App\Services;

use App\Models\GuestPreOrderRound;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Retire unconfirmed selections at T−30 without deleting prices, notes or history. */
class PreOrderCutoffService
{
    public function available(): bool
    {
        return Schema::hasColumn('pre_order_items', 'cancelled_at')
            && Schema::hasColumn('guest_pre_order_rounds', 'cancelled_at');
    }

    /** @return Builder<Reservation> */
    public function due(): Builder
    {
        // Code can be deployed before the additive cutoff migration is applied.
        if (! $this->available()) {
            return Reservation::query()->whereRaw('1 = 0');
        }

        return Reservation::query()->whereIn('status', ['draft', 'confirmed'])
            ->where('reserved_at', '<=', now()->addMinutes(30))
            ->where(fn (Builder $query) => $query
                ->whereHas('preOrder.items', fn (Builder $items) => $items->whereNull('superseded_at')
                    ->where(fn (Builder $pending) => $pending->whereNull('review_published_at')->orWhere('restaurant_decision', '!=', 'confirmed')))
                ->orWhereHas('guests', fn (Builder $guests) => $guests->whereIn('id', GuestPreOrderRound::query()
                    ->select('reservation_guest_id')->whereNull('submitted_at')->whereNull('cancelled_at'))));
    }

    public function expire(Reservation $reservation): int
    {
        return DB::transaction(function () use ($reservation): int {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            return $this->expireLocked($locked);
        }, attempts: 3);
    }

    /** Caller holds the reservation's exclusive lock and has authorized access. */
    public function expireLocked(Reservation $reservation): int
    {
        if (! in_array($reservation->status, ['draft', 'confirmed'], true) || ! PreOrderTiming::locked($reservation) || ! $this->available()) {
            return 0;
        }
        $order = $reservation->preOrder()->lockForUpdate()->first();
        $guests = $reservation->guests()->orderBy('id')->lockForUpdate()->get();
        $cutoff = $reservation->reserved_at->toImmutable()->subMinutes(30);
        $changed = collect();
        $count = 0;
        if ($order !== null) {
            $items = $order->items()->whereNull('superseded_at')
                ->where(fn (Builder $query) => $query->whereNull('review_published_at')->orWhere('restaurant_decision', '!=', 'confirmed'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->forceFill(['cancelled_at' => $cutoff, 'superseded_at' => $cutoff])->save();
                $changed->push($item->reservation_guest_id);
                $count++;
            }
        }
        $rounds = GuestPreOrderRound::query()->whereIn('reservation_guest_id', $guests->modelKeys())
            ->whereNull('submitted_at')->whereNull('cancelled_at')->orderBy('id')->lockForUpdate()->get();
        foreach ($rounds as $round) {
            $round->forceFill(['cancelled_at' => $cutoff])->save();
            $changed->push($round->reservation_guest_id);
        }
        foreach ($guests->whereIn('id', $changed->unique()) as $guest) {
            $guest->increment('selection_version');
        }

        return $count;
    }
}
