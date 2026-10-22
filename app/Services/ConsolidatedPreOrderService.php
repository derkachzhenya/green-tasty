<?php

namespace App\Services;

use App\Models\Reservation;
use Brick\Math\BigDecimal;

/**
 * @phpstan-type DishSummary array{menu_item_id: int, name: string, quantity: int, subtotal: string}
 * @phpstan-type GuestItem array{menu_item_id: int, name: string, quantity: int, unit_price: string, subtotal: string}
 * @phpstan-type GuestSummary array{reservation_guest_id: int, name: string, items: list<GuestItem>, total_quantity: int, subtotal: string}
 * @phpstan-type OrderSummary array{reservation_id: int, pre_order_id: int|null, items: list<DishSummary>, guests: list<GuestSummary>, total_quantity: int, grand_total: string}
 */
class ConsolidatedPreOrderService
{
    /**
     * Summarize persisted selections, including only guests with items in this reservation's order.
     * Always query the scoped relations rather than trusting previously loaded model relations.
     *
     * @return OrderSummary
     */
    public function summarize(Reservation $reservation): array
    {
        $preOrder = $reservation->preOrder()->first();
        $summary = [
            'reservation_id' => $reservation->id,
            'pre_order_id' => $preOrder?->id,
            'items' => [],
            'guests' => [],
            'total_quantity' => 0,
            'grand_total' => '0.00',
        ];

        if ($preOrder === null) {
            return $summary;
        }

        $items = $preOrder->items()
            ->whereRelation('guest', 'reservation_id', $reservation->id)
            ->with(['guest', 'menuItem'])
            ->orderBy('id')
            ->get();

        $dishes = [];
        $guests = [];
        $grandTotal = BigDecimal::zero();

        foreach ($items as $item) {
            $guest = $item->guest;
            $menuItem = $item->menuItem;

            if ($guest === null || $menuItem === null) {
                continue;
            }

            // Laravel's decimal cast returns a string; all arithmetic stays in decimal form.
            $unitPrice = BigDecimal::of($item->unit_price);
            $subtotal = $unitPrice->multipliedBy($item->quantity);

            $dishes[$menuItem->id] ??= [
                'menu_item_id' => $menuItem->id,
                'name' => $menuItem->name,
                'quantity' => 0,
                'subtotal' => '0.00',
            ];
            $dishes[$menuItem->id]['quantity'] += $item->quantity;
            $dishes[$menuItem->id]['subtotal'] = $this->money(
                BigDecimal::of($dishes[$menuItem->id]['subtotal'])->plus($subtotal)
            );

            $guests[$guest->id] ??= [
                'reservation_guest_id' => $guest->id,
                'name' => $guest->name,
                'items' => [],
                'total_quantity' => 0,
                'subtotal' => '0.00',
            ];
            $guests[$guest->id]['items'][] = [
                'menu_item_id' => $menuItem->id,
                'name' => $menuItem->name,
                'quantity' => $item->quantity,
                'unit_price' => $this->money($unitPrice),
                'subtotal' => $this->money($subtotal),
            ];
            $guests[$guest->id]['total_quantity'] += $item->quantity;
            $guests[$guest->id]['subtotal'] = $this->money(
                BigDecimal::of($guests[$guest->id]['subtotal'])->plus($subtotal)
            );

            $summary['total_quantity'] += $item->quantity;
            $grandTotal = $grandTotal->plus($subtotal);
        }

        $summary['items'] = array_values($dishes);
        $summary['guests'] = array_values($guests);
        $summary['grand_total'] = $this->money($grandTotal);

        return $summary;
    }

    private function money(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2);
    }
}
