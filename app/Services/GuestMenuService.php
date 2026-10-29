<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SensitiveParameter;

/**
 * @phpstan-import-type InvitationView from GuestInvitationService
 * @phpstan-import-type SelectionSummary from GuestDishSelectionService
 *
 * @phpstan-type CatalogDish array{id: int, name: string, description: string|null, price: string, available: bool, created_at: string|null, popularity: int, sort_order: int}
 * @phpstan-type CatalogCategory array{id: int, name: string, dishes: list<CatalogDish>}
 * @phpstan-type GuestContext array{restaurant: string, address: string, table: string, date: string, time: string, end_date: string, end_time: string, guests: int}
 * @phpstan-type GuestState array{name_confirmed: bool, submitted_at: string|null, version: int, draft_round: int|null, can_start_new: bool}
 * @phpstan-type Timing array{server_now: string, warning_at: string, locks_at: string, locked: bool, unconfirmed: bool}
 * @phpstan-type GuestMenu array{timing: Timing, context: GuestContext, guest_state: GuestState, catalog: list<CatalogCategory>,invitation: InvitationView, selections: SelectionSummary, previous_selections: SelectionSummary|null, order: array{status: string|null, submitted_at: string|null, editable: bool}, timezone: string}
 */
class GuestMenuService
{
    public function __construct(private GuestInvitationService $invitations, private GuestDishSelectionService $selections, private GuestAdditionalPreOrderService $additional) {}

    /** @return GuestMenu|null */
    public function view(#[SensitiveParameter] string $token): ?array
    {
        return DB::transaction(function () use ($token): ?array {
            $initial = $this->invitations->resolve($token);
            if ($initial === null) {
                return null;
            }

            // Match selection/submission lock order. Keep the saved selections and status consistent.
            $reservation = Reservation::query()->whereKey($initial->reservation_id)->lockForUpdate()->firstOrFail();
            $guest = $this->invitations->resolve($token, lockForUpdate: true);
            if ($guest === null || $guest->reservation_id !== $reservation->id) {
                return null;
            }

            app(PreOrderCutoffService::class)->expireLocked($reservation);
            $guest->refresh();
            $invitation = $this->invitations->view($token);
            $selections = $this->selections->view($token);
            if ($invitation === null || $selections === null) {
                return null;
            }

            return $this->build($reservation, $guest, $selections, $invitation);
        }, attempts: 3);
    }

    /** @return GuestMenu */
    public function forOwner(Reservation $reservation, User $owner): array
    {
        return DB::transaction(function () use ($reservation, $owner): array {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('view', $locked);
            app(PreOrderCutoffService::class)->expireLocked($locked);
            $guest = $locked->guests()->where('user_id', $owner->id)->lockForUpdate()->firstOrFail();

            return $this->build($locked, $guest, $this->selections->forGuest($guest), null, true);
        }, attempts: 3);
    }

    /**
     * @param  SelectionSummary  $selections
     * @param  InvitationView|null  $invitation
     * @return GuestMenu
     */
    private function build(Reservation $reservation, ReservationGuest $guest, array $selections, ?array $invitation, bool $owner = false): array
    {
        $order = $reservation->preOrder()->first();
        $reservation->load(['restaurant', 'table']);
        $start = $reservation->reserved_at->toImmutable()->setTimezone(BookingRules::TIMEZONE);
        $end = $reservation->endsAt()->setTimezone(BookingRules::TIMEZONE);
        $draft = $owner ? null : $this->additional->draft($guest);
        $open = ! PreOrderTiming::locked($reservation) && ReservationCardState::acceptsPreOrder($reservation)
            && ($order === null || ($order->status === 'draft' && $order->submitted_at === null));
        $catalog = $reservation->restaurant->menuCategories()->where('is_active', true)
            ->with(['items' => fn ($query) => $query->withSum([
                'preOrderItems as ordered_quantity' => fn (Builder $query) => $query->whereHas('preOrder', fn (Builder $query) => $query->where('status', 'submitted')),
            ], 'quantity')->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')->get();

        return [
            'timing' => PreOrderTiming::state($reservation),
            'context' => [
                'restaurant' => $reservation->restaurant->name,
                'address' => $reservation->restaurant->address,
                'table' => $reservation->restaurant_table_id === null ? 'No longer assigned' : $reservation->table->name,
                'date' => $start->format('Y-m-d'), 'time' => $start->format('H:i'),
                'end_date' => $end->format('Y-m-d'), 'end_time' => $end->format('H:i'),
                'guests' => $reservation->guest_count,
            ],
            'guest_state' => ['name_confirmed' => $owner || $guest->name_confirmed_at !== null,
                'submitted_at' => $guest->pre_order_submitted_at?->toIso8601String(),
                'version' => $guest->selection_version,
                'draft_round' => $draft?->number,
                'can_start_new' => ! $owner && $open && $guest->pre_order_submitted_at !== null && $draft === null && $this->additional->available()],
            'catalog' => array_values($catalog->map(fn (MenuCategory $category): array => [
                'id' => $category->id, 'name' => $category->name,
                'dishes' => array_values($category->items->map(fn (MenuItem $dish): array => [
                    'id' => $dish->id, 'name' => $dish->name, 'description' => $dish->description,
                    'price' => $dish->price, 'available' => $dish->is_available,
                    'created_at' => $dish->created_at?->toIso8601String(),
                    'popularity' => (int) $dish->getAttribute('ordered_quantity'), 'sort_order' => $dish->sort_order,
                ])->all()),
            ])->all()),
            'invitation' => $invitation ?? [
                'restaurant' => ['name' => $reservation->restaurant->name],
                'reservation' => ['reserved_at' => $reservation->reserved_at->toIso8601String()],
                'guest' => ['name' => $guest->name],
                'menu_categories' => array_values($catalog->map(fn (MenuCategory $category): array => [
                    'id' => $category->id, 'name' => $category->name,
                    'dishes' => array_values($category->items->filter(fn (MenuItem $dish): bool => $dish->is_available)->map(fn (MenuItem $dish): array => [
                        'id' => $dish->id, 'name' => $dish->name, 'description' => $dish->description, 'price' => $dish->price,
                    ])->all()),
                ])->all()),
            ],
            'selections' => $selections,
            'previous_selections' => $draft === null ? null : $this->selections->forGuest($guest),
            'order' => [
                'status' => $order?->status,
                'submitted_at' => $order?->submitted_at?->toIso8601String(),
                'editable' => ! PreOrderTiming::locked($reservation) && ($owner || $guest->pre_order_submitted_at === null || $draft !== null)
                            && (! $owner || ReservationCardState::acceptsPreOrder($reservation))
                    && ! in_array($reservation->status, ['cancelled', 'completed'], true)
                    && ($order === null || ($order->status === 'draft' && $order->submitted_at === null) || ($owner && $order->status === 'submitted' && $order->submitted_at !== null)),
            ],
            'timezone' => BookingRules::TIMEZONE,
        ];
    }
}
