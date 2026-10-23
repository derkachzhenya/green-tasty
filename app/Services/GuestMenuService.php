<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * @phpstan-import-type InvitationView from GuestInvitationService
 * @phpstan-import-type SelectionSummary from GuestDishSelectionService
 *
 * @phpstan-type GuestMenu array{invitation: InvitationView, selections: SelectionSummary, order: array{status: string|null, submitted_at: string|null, editable: bool}, timezone: string}
 */
class GuestMenuService
{
    public function __construct(private GuestInvitationService $invitations, private GuestDishSelectionService $selections) {}

    /** @return GuestMenu|null */
    public function view(#[SensitiveParameter] string $token): ?array
    {
        return DB::transaction(function () use ($token): ?array {
            $initial = $this->invitations->resolve($token);
            if ($initial === null) {
                return null;
            }

            // Match selection/submission lock order. Keep the saved selections and status consistent.
            $reservation = Reservation::query()->whereKey($initial->reservation_id)->sharedLock()->firstOrFail();
            $guest = $this->invitations->resolve($token, lockForUpdate: true);
            if ($guest === null || $guest->reservation_id !== $reservation->id) {
                return null;
            }

            $invitation = $this->invitations->view($token);
            $selections = $this->selections->view($token);
            if ($invitation === null || $selections === null) {
                return null;
            }
            $order = $reservation->preOrder()->first();

            return [
                'invitation' => $invitation,
                'selections' => $selections,
                'order' => [
                    'status' => $order?->status,
                    'submitted_at' => $order?->submitted_at?->toIso8601String(),
                    'editable' => $order === null || ($order->status === 'draft' && $order->submitted_at === null),
                ],
                'timezone' => BookingRules::TIMEZONE,
            ];
        }, attempts: 3);
    }
}
