<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @phpstan-type IssuedInvitation array{guest_id: int, name: string, expires_at: string, invitation_url: string}
 * @phpstan-type GuestMenuItem array{id: int, name: string, description: string|null, price: string}
 * @phpstan-type GuestMenuCategory array{id: int, name: string, dishes: list<GuestMenuItem>}
 * @phpstan-type InvitationView array{restaurant: array{name: string}, reservation: array{reserved_at: string}, guest: array{name: string}, menu_categories: list<GuestMenuCategory>}
 */
class GuestInvitationService
{
    /**
     * @param  array<string, mixed>  $attributes  Validated guest attributes only.
     * @return IssuedInvitation
     */
    public function create(Reservation $reservation, array $attributes, ?string $expiresAt = null): array
    {
        return DB::transaction(function () use ($reservation, $attributes, $expiresAt): array {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($locked);
            $guest = $locked->guests()->create($attributes);

            return $this->issue($guest, $expiresAt);
        });
    }

    /** @return IssuedInvitation */
    public function regenerate(Reservation $reservation, ReservationGuest $guest, ?string $expiresAt = null): array
    {
        return DB::transaction(function () use ($reservation, $guest, $expiresAt): array {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $this->ensureOpen($locked);
            $lockedGuest = $locked->guests()->whereKey($guest->id)->lockForUpdate()->firstOrFail();

            return $this->issue($lockedGuest, $expiresAt);
        });
    }

    public function revoke(Reservation $reservation, ReservationGuest $guest): void
    {
        DB::transaction(function () use ($reservation, $guest): void {
            $lockedReservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            PreOrderTiming::ensureOpen($lockedReservation);
            $lockedGuest = $lockedReservation->guests()->whereKey($guest->id)->lockForUpdate()->firstOrFail();
            $lockedGuest->invitation_token = null;
            $lockedGuest->invitation_revoked_at = CarbonImmutable::now();
            $lockedGuest->save();
        });
    }

    /** @return InvitationView|null */
    public function view(#[SensitiveParameter] string $token): ?array
    {
        $guest = $this->resolve($token);

        if ($guest === null) {
            return null;
        }

        $guest->load('reservation.restaurant');
        $reservation = $guest->reservation;
        $restaurant = $reservation?->restaurant;

        if ($reservation === null || $restaurant === null) {
            return null;
        }

        $categories = $restaurant->menuCategories()
            ->where('is_active', true)
            ->with(['items' => fn ($items) => $items->where('is_available', true)->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'restaurant' => ['name' => $restaurant->name],
            'reservation' => ['reserved_at' => $reservation->reserved_at->toIso8601String()],
            'guest' => ['name' => $guest->name],
            'menu_categories' => array_values($categories->map(fn (MenuCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'dishes' => array_values($category->items->map(fn (MenuItem $item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'price' => $item->price,
                ])->all()),
            ])->all()),
        ];
    }

    /** Acquire the optional row lock only inside a database transaction. */
    public function resolve(#[SensitiveParameter] string $token, bool $lockForUpdate = false): ?ReservationGuest
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        $query = ReservationGuest::query()
            ->where('invitation_token', hash('sha256', $token))
            ->whereNull('invitation_revoked_at')
            ->where('invitation_expires_at', '>', CarbonImmutable::now());

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $guest = $query->first();

        // A locking query may have waited until after expiration.
        if ($guest?->invitation_expires_at?->isFuture() !== true) {
            return null;
        }

        return $guest;
    }

    private function ensureOpen(Reservation $reservation): void
    {
        $order = $reservation->preOrder()->lockForUpdate()->first();
        if (! ReservationCardState::acceptsPreOrder($reservation) || ($order !== null && ($order->status !== 'draft' || $order->submitted_at !== null))) {
            throw new ConflictHttpException('This reservation is no longer accepting invitations.');
        }
    }

    /** @return IssuedInvitation */
    private function issue(ReservationGuest $guest, ?string $expiresAt): array
    {
        $token = bin2hex(random_bytes(32));
        $expiration = $expiresAt === null
            ? CarbonImmutable::now()->addDays(7)
            : CarbonImmutable::parse($expiresAt);

        $guest->invitation_token = hash('sha256', $token);
        $guest->invitation_expires_at = $expiration;
        $guest->invitation_revoked_at = null;
        $guest->save();

        return [
            'guest_id' => $guest->id,
            'name' => $guest->name,
            'expires_at' => $expiration->toIso8601String(),
            'invitation_url' => route('invitations.show', ['token' => $token]),
        ];
    }
}
