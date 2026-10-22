<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @phpstan-import-type DishSummary from ConsolidatedPreOrderService
 * @phpstan-import-type GuestSummary from ConsolidatedPreOrderService
 *
 * @phpstan-type SubmittedSummary array{reservation_id: int, pre_order_id: int|null, items: list<DishSummary>, guests: list<GuestSummary>, total_quantity: int, grand_total: string, status: 'submitted', submitted_at: string}
 */
class GroupPreOrderSubmissionService
{
    public function __construct(private ConsolidatedPreOrderService $summaries) {}

    /** @return SubmittedSummary */
    public function submit(Reservation $reservation, User $owner): array
    {
        return DB::transaction(function () use ($reservation, $owner): array {
            // Guest replacements acquire this same parent lock before touching the order.
            $lockedReservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('submitPreOrder', $lockedReservation);
            $preOrder = $lockedReservation->preOrder()->lockForUpdate()->first();

            if ($preOrder === null) {
                throw ValidationException::withMessages(['pre_order' => 'The group pre-order is empty.']);
            }

            $submittedAt = $preOrder->submitted_at;
            $alreadySubmitted = $preOrder->status === 'submitted' && $submittedAt !== null;

            if (! $alreadySubmitted) {
                if ($preOrder->status !== 'draft' || $submittedAt !== null) {
                    throw new ConflictHttpException('The pre-order cannot be submitted from its current state.');
                }

                if (in_array($lockedReservation->status, ['cancelled', 'completed'], true)) {
                    throw new ConflictHttpException('This reservation cannot accept a pre-order submission.');
                }
            }

            $items = $preOrder->items()->with(['guest', 'menuItem.category'])->orderBy('id')->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['pre_order' => 'The group pre-order is empty.']);
            }

            foreach ($items as $item) {
                if ($item->guest?->reservation_id !== $lockedReservation->id
                    || $item->menuItem?->category?->restaurant_id !== $lockedReservation->restaurant_id
                    || $item->quantity < 1
                    || BigDecimal::of($item->unit_price)->isNegative()) {
                    throw ValidationException::withMessages(['pre_order' => 'The group pre-order contains invalid selections.']);
                }
            }

            if (! $alreadySubmitted) {
                $submittedAt = CarbonImmutable::now();
                $preOrder->fill(['status' => 'submitted', 'submitted_at' => $submittedAt])->save();
            }

            return [
                ...$this->summaries->summarize($lockedReservation),
                'status' => 'submitted',
                'submitted_at' => $submittedAt->toIso8601String(),
            ];
        }, attempts: 3);
    }
}
