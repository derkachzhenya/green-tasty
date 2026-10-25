<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * @phpstan-import-type InvitationView from GuestInvitationService
 * @phpstan-import-type SelectionSummary from GuestDishSelectionService
 *
 * @phpstan-type CatalogDish array{id: int, name: string, description: string|null, price: string, available: bool, created_at: string|null, popularity: int, sort_order: int}
 * @phpstan-type CatalogCategory array{id: int, name: string, dishes: list<CatalogDish>}
 * @phpstan-type GuestContext array{restaurant: string, address: string, table: string, date: string, time: string, end_date: string, end_time: string, guests: int}
 * @phpstan-type GuestState array{name_confirmed: bool, submitted_at: string|null, version: int}
 * @phpstan-type GuestMenu array{context: GuestContext, guest_state: GuestState, catalog: list<CatalogCategory>,invitation: InvitationView, selections: SelectionSummary, order: array{status: string|null, submitted_at: string|null, editable: bool}, timezone: string}
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
            $reservation->load(['restaurant', 'table']);
            $start = $reservation->reserved_at->toImmutable()->setTimezone(BookingRules::TIMEZONE);
            $end = $start->addMinutes(BookingRules::DURATION_MINUTES);
            $catalog = $reservation->restaurant->menuCategories()->where('is_active', true)
                ->with(['items' => fn ($query) => $query->withSum([
                    'preOrderItems as ordered_quantity' => fn (Builder $query) => $query->whereHas('preOrder', fn (Builder $query) => $query->where('status', 'submitted')),
                ], 'quantity')->orderBy('sort_order')->orderBy('id')])
                ->orderBy('sort_order')->orderBy('id')->get();

            return [
                'context' => [
                    'restaurant' => $reservation->restaurant->name,
                    'address' => $reservation->restaurant->address,
                    'table' => $reservation->restaurant_table_id === null ? 'No longer assigned' : $reservation->table->name,
                    'date' => $start->format('Y-m-d'), 'time' => $start->format('H:i'),
                    'end_date' => $end->format('Y-m-d'), 'end_time' => $end->format('H:i'),
                    'guests' => $reservation->guest_count,
                ],
                'guest_state' => ['name_confirmed' => $guest->name_confirmed_at !== null,
                    'submitted_at' => $guest->pre_order_submitted_at?->toIso8601String(),
                    'version' => $guest->selection_version],
                'catalog' => array_values($catalog->map(fn (MenuCategory $category): array => [
                    'id' => $category->id, 'name' => $category->name,
                    'dishes' => array_values($category->items->map(fn (MenuItem $dish): array => [
                        'id' => $dish->id, 'name' => $dish->name, 'description' => $dish->description,
                        'price' => $dish->price, 'available' => $dish->is_available,
                        'created_at' => $dish->created_at?->toIso8601String(),
                        'popularity' => (int) $dish->getAttribute('ordered_quantity'), 'sort_order' => $dish->sort_order,
                    ])->all()),
                ])->all()),
                'invitation' => $invitation,
                'selections' => $selections,
                'order' => [
                    'status' => $order?->status,
                    'submitted_at' => $order?->submitted_at?->toIso8601String(),
                    'editable' => $guest->pre_order_submitted_at === null
                        && ! in_array($reservation->status, ['cancelled', 'completed'], true)
                        && ($order === null || ($order->status === 'draft' && $order->submitted_at === null)),
                ],
                'timezone' => BookingRules::TIMEZONE,
            ];
        }, attempts: 3);
    }
}
