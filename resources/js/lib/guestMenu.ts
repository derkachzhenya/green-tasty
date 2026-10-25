import catalog from '../../data/restaurant-menu.json';
import { cents } from '@/lib/guestSelection';
import type { GuestCatalogDish } from '@/types/guest-selection';

export type MenuSort = 'popular' | 'newest' | 'price-asc' | 'price-desc';

function catalogDish(name: string) {
    return catalog.dishes.find(
        (dish) => dish.name.toLowerCase() === name.trim().toLowerCase(),
    );
}

export function dishImage(name: string): string | null {
    return catalogDish(name)?.image ?? null;
}

export function dishWeight(name: string): string | null {
    return catalogDish(name)?.weight ?? null;
}

export function dishImageCropped(name: string): boolean {
    return catalogDish(name)?.crop ?? false;
}

export function sortedDishes(
    dishes: GuestCatalogDish[],
    sort: MenuSort,
): GuestCatalogDish[] {
    return [...dishes].sort((a, b) => {
        if (sort === 'price-asc' || sort === 'price-desc') {
            const difference = cents(a.price) - cents(b.price);
            if (difference !== 0n)
                return (
                    (difference < 0n ? -1 : 1) * (sort === 'price-asc' ? 1 : -1)
                );
        }
        if (sort === 'newest') {
            const difference =
                (b.created_at ? Date.parse(b.created_at) : 0) -
                (a.created_at ? Date.parse(a.created_at) : 0);
            if (difference) return difference;
        }
        if (sort === 'popular' && a.popularity !== b.popularity)
            return b.popularity - a.popularity;
        return a.sort_order - b.sort_order || a.id - b.id;
    });
}
