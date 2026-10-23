import type {
    DraftDish,
    GuestMenu,
    SelectionInput,
} from '@/types/guest-selection';

export function cents(value: string): bigint {
    if (!/^\d+\.\d{2}$/.test(value)) throw new Error('Invalid decimal price.');
    return BigInt(value.replace('.', ''));
}

export function decimal(value: bigint): string {
    return `${value / 100n}.${(value % 100n).toString().padStart(2, '0')}`;
}

export function draftDishes(
    menu: GuestMenu,
    input: SelectionInput[],
): DraftDish[] {
    const available = new Map(
        menu.invitation.menu_categories.flatMap((category) =>
            category.dishes.map((dish) => [dish.id, dish] as const),
        ),
    );
    const saved = new Map(
        menu.selections.items.map((item) => [item.menu_item_id, item]),
    );
    return input.flatMap(({ menu_item_id, quantity }) => {
        const dish = available.get(menu_item_id);
        const original = saved.get(menu_item_id);
        if (!dish && !original) return [];
        // Retained rows keep their snapshot, even if removed and re-added before saving.
        const unit_price = original?.unit_price ?? dish!.price;
        return [
            {
                menu_item_id,
                name: original?.name ?? dish!.name,
                quantity,
                unit_price,
                subtotal: decimal(cents(unit_price) * BigInt(quantity)),
                available: Boolean(dish),
            },
        ];
    });
}

export function selectionKey(input: SelectionInput[]): string {
    return [...input]
        .sort((a, b) => a.menu_item_id - b.menu_item_id)
        .map((item) => `${item.menu_item_id}:${item.quantity}`)
        .join(',');
}
