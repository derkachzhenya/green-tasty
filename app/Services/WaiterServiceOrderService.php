<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A service order is separate from immutable customer pre-order/review history. */
class WaiterServiceOrderService
{
    /** @return array<string, mixed> */
    public function view(Restaurant $restaurant, Reservation $reservation, User $staff): array
    {
        return DB::transaction(fn (): array => $this->snapshot($this->lock($restaurant, $reservation, $staff)), attempts: 3);
    }

    /** @param list<array{line_key: string, menu_item_id: int, quantity: int, notes?: string|null}> $items
     * @return array<string, mixed>
     */
    public function save(Restaurant $restaurant, Reservation $reservation, User $staff, array $items, string $version): array
    {
        return DB::transaction(function () use ($restaurant, $reservation, $staff, $items, $version): array {
            $locked = $this->lock($restaurant, $reservation, $staff);
            $state = $this->snapshot($locked);
            if (! $state['editable'] || ! hash_equals($state['version'], $version)) {
                throw new ConflictHttpException('The order changed or the visit is no longer in progress. Refresh before saving.');
            }
            if ($items === []) {
                throw ValidationException::withMessages(['items' => 'Add at least one dish to the service order.']);
            }
            $ids = array_column($items, 'menu_item_id');
            $categories = MenuCategory::query()->where('restaurant_id', $locked->restaurant_id)->where('is_active', true)->orderBy('id')->sharedLock()->pluck('id');
            $dishes = MenuItem::query()->whereIn('menu_category_id', $categories)->whereIn('id', $ids)->orderBy('id')->sharedLock()->get()->keyBy('id');
            $existing = collect($state['items'])->keyBy('line_key');
            $saved = [];
            $total = BigDecimal::zero();
            foreach ($items as $item) {
                $dish = $dishes->get($item['menu_item_id']);
                $old = $existing->get($item['line_key']);
                if (($old !== null && $old['menu_item_id'] !== $item['menu_item_id']) || ($old === null && $item['line_key'] !== 'new-'.$item['menu_item_id'])) {
                    throw ValidationException::withMessages(['items' => 'Refresh the order before changing its lines.']);
                }
                // Retain an existing unavailable dish, but do not add/increase it.
                if ($dish === null || (! $dish->is_available && ($old === null || $item['quantity'] > $old['quantity']))) {
                    throw ValidationException::withMessages(['items' => 'A selected dish is unavailable for this restaurant.']);
                }
                $price = $old !== null ? $old['unit_price'] : $dish->price;
                $amount = BigDecimal::of($price)->multipliedBy($item['quantity']);
                $saved[] = ['line_key' => $item['line_key'], 'menu_item_id' => $dish->id, 'name' => $old['name'] ?? $dish->name, 'quantity' => $item['quantity'], 'unit_price' => $price,
                    'notes' => $item['notes'] ?? null, 'total' => (string) $amount->toScale(2)];
                $total = $total->plus($amount);
            }
            $order = DB::table('restaurant_service_orders')->where('reservation_id', $locked->id)->first();
            if ($order !== null && $saved === $state['items']) {
                return $state;
            }
            $revision = $order === null ? 1 : $order->revision + 1;
            $orderId = $order !== null ? $order->id : DB::table('restaurant_service_orders')->insertGetId(['reservation_id' => $locked->id, 'revision' => $revision, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('restaurant_service_order_revisions')->insert(['service_order_id' => $orderId, 'revision' => $revision, 'actor_user_id' => $staff->id,
                'items' => json_encode($saved, JSON_THROW_ON_ERROR), 'total' => (string) $total->toScale(2), 'created_at' => now()]);
            DB::table('restaurant_service_orders')->where('id', $orderId)->update(['revision' => $revision, 'updated_at' => now()]);

            return $this->snapshot($locked);
        }, attempts: 3);
    }

    private function lock(Restaurant $restaurant, Reservation $reservation, User $staff): Reservation
    {
        Gate::forUser($staff)->authorize('staff', $restaurant);
        $locked = $restaurant->reservations()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($staff)->authorize('staff', $restaurant);

        return $locked;
    }

    /** @return array{items: list<array{line_key: string, menu_item_id: int, name: string, quantity: int, unit_price: string, notes: string|null, total: string}>, editable: bool, version: string, exists: bool, revision: int, phase: string, blocked_reason: string|null, total: string, catalog: array<int, mixed>} */
    private function snapshot(Reservation $reservation): array
    {
        $order = DB::table('restaurant_service_orders')->where('reservation_id', $reservation->id)->first();
        $revision = $order === null ? null : DB::table('restaurant_service_order_revisions')->where('service_order_id', $order->id)->where('revision', $order->revision)->first();
        $items = $revision === null ? $this->confirmedItems($reservation) : $this->decodeItems($revision->items);
        $total = BigDecimal::zero();
        foreach ($items as $item) {
            $total = $total->plus($item['total']);
        }
        $phase = ReservationCardState::phase($reservation);
        $preOrder = $reservation->preOrder()->first();
        $pendingReview = $preOrder?->status === 'submitted' && (int) $preOrder->review_revision !== (int) $preOrder->reviewed_revision;
        $editable = $phase === 'in_progress' && $reservation->status === 'confirmed' && ! $pendingReview;
        $catalog = $editable ? MenuItem::query()->whereHas('category', fn ($q) => $q->where('restaurant_id', $reservation->restaurant_id)->where('is_active', true))->orderBy('name')->get(['id', 'name', 'price', 'is_available'])->toArray() : [];

        return ['exists' => $order !== null, 'revision' => $order !== null ? $order->revision : 0, 'editable' => $editable, 'phase' => $phase,
            'version' => '"'.hash('sha256', json_encode([$reservation->only(['id', 'status', 'reserved_at', 'duration_minutes', 'restaurant_table_id', 'updated_at']), $order, $items], JSON_THROW_ON_ERROR)).'"',
            'blocked_reason' => $phase === 'in_progress' && $pendingReview ? 'Review and submit the customer pre-order before editing this order.' : null,
            'items' => $items, 'total' => (string) $total->toScale(2), 'catalog' => $catalog];
    }

    /** @return list<array{line_key: string, menu_item_id: int, name: string, quantity: int, unit_price: string, notes: string|null, total: string}> */
    private function confirmedItems(Reservation $reservation): array
    {
        $order = $reservation->preOrder()->first();
        if ($order === null || $order->status !== 'submitted') {
            return [];
        }

        return array_values($order->items()->whereNull('superseded_at')->whereNotNull('review_published_at')->where('restaurant_decision', 'confirmed')
            ->whereHas('guest', fn ($q) => $q->whereNull('removed_at'))->with('menuItem')->orderBy('id')->get()->map(fn ($item): array => [
                'line_key' => 'preorder-'.$item->id, 'menu_item_id' => $item->menu_item_id, 'name' => $item->menuItem->name,
                'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'notes' => $item->notes,
                'total' => (string) BigDecimal::of($item->unit_price)->multipliedBy($item->quantity)->toScale(2),
            ])->all());
    }

    /** @return list<array{line_key: string, menu_item_id: int, name: string, quantity: int, unit_price: string, notes: string|null, total: string}> */
    private function decodeItems(string $json): array
    {
        $rows = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new \LogicException('Invalid saved service order.');
        }
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['line_key'] ?? null) || ! is_int($row['menu_item_id'] ?? null) || ! is_string($row['name'] ?? null) || ! is_int($row['quantity'] ?? null) || ! is_string($row['unit_price'] ?? null) || ! is_string($row['total'] ?? null) || ! array_key_exists('notes', $row) || ($row['notes'] !== null && ! is_string($row['notes']))) {
                throw new \LogicException('Invalid saved service order line.');
            }
            $items[] = ['line_key' => $row['line_key'], 'menu_item_id' => $row['menu_item_id'], 'name' => $row['name'], 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price'], 'notes' => $row['notes'], 'total' => $row['total']];
        }

        return $items;
    }
}
