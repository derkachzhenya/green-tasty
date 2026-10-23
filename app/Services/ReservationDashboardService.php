<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationGuest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * @phpstan-import-type OrderSummary from ConsolidatedPreOrderService
 *
 * @phpstan-type InvitationStatus 'owner'|'not_invited'|'active'|'expired'|'revoked'|'unavailable'
 * @phpstan-type Participant array{id: int, name: string, is_owner: bool, response_status: string, invitation_status: InvitationStatus, invitation_expires_at: string|null}
 * @phpstan-type DashboardOrder array{participants: list<Participant>, summary: OrderSummary, status: string|null, submitted_at: string|null}
 */
class ReservationDashboardService
{
    public function __construct(private ConsolidatedPreOrderService $summaries) {}

    /** @return DashboardOrder */
    public function groupOrder(Reservation $reservation): array
    {
        return DB::transaction(function () use ($reservation): array {
            // Selection updates and submission lock this same parent before touching the order.
            $locked = Reservation::query()->whereKey($reservation->id)->sharedLock()->firstOrFail();
            Gate::authorize('view', $locked);
            Gate::authorize('viewSummary', $locked);
            $preOrder = $locked->preOrder()->first();
            $summary = $this->summaries->summarize($locked);
            $participants = $locked->guests()->select([
                'id', 'reservation_id', 'user_id', 'name', 'status',
                'invitation_token', 'invitation_expires_at', 'invitation_revoked_at',
            ])->orderBy('id')->get();

            return [
                'participants' => array_values($participants->map(fn (ReservationGuest $guest): array => [
                    'id' => $guest->id,
                    'name' => $guest->name,
                    'is_owner' => $guest->user_id === $locked->owner_user_id,
                    'response_status' => $guest->status,
                    'invitation_status' => $this->invitationStatus($guest, $locked),
                    'invitation_expires_at' => $guest->invitation_expires_at?->toIso8601String(),
                ])->all()),
                'summary' => $summary,
                'status' => $preOrder?->status,
                'submitted_at' => $preOrder?->submitted_at?->toIso8601String(),
            ];
        }, attempts: 3);
    }

    /** @return InvitationStatus */
    private function invitationStatus(ReservationGuest $guest, Reservation $reservation): string
    {
        if ($guest->user_id === $reservation->owner_user_id) {
            return 'owner';
        }
        if ($guest->invitation_revoked_at !== null) {
            return 'revoked';
        }
        if ($guest->invitation_token === null) {
            return 'not_invited';
        }
        if ($guest->invitation_expires_at === null) {
            return 'unavailable';
        }

        return $guest->invitation_expires_at->greaterThan(CarbonImmutable::now()) ? 'active' : 'expired';
    }
}
