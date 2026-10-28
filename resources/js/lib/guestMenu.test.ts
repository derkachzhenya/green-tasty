import { describe, expect, it } from 'vite-plus/test';
import catalog from '../../data/restaurant-menu.json';
import type { GuestCatalogDish } from '@/types/guest-selection';
import {
    dishImage,
    dishImageCropped,
    dishWeight,
    dishDescription,
    dishNutrition,
    sortedDishes,
} from './guestMenu';
const dishes: GuestCatalogDish[] = [
    {
        id: 1,
        name: 'First',
        description: null,
        price: '100.00',
        available: true,
        created_at: '2026-01-01T00:00:00Z',
        popularity: 2,
        sort_order: 1,
    },
    {
        id: 2,
        name: 'Second',
        description: null,
        price: '9.99',
        available: false,
        created_at: '2026-02-01T00:00:00Z',
        popularity: 4,
        sort_order: 0,
    },
    {
        id: 3,
        name: 'Third',
        description: null,
        price: '0.10',
        available: true,
        created_at: null,
        popularity: 0,
        sort_order: 2,
    },
];
describe('guest menu filters and presentation', () => {
    it('has descriptions for the reference dishes and only supplied nutrition facts', () => {
        for (const dish of catalog.dishes)
            expect(dishDescription(dish.name)).toBeTruthy();
        expect(dishDescription('Avocado Pine Nut Bowl')).toContain('avocado');
        expect(dishNutrition('Fresh Strawberry Mint Salad')?.[0][0].value).toBe(
            '~503 kcal',
        );
        expect(dishNutrition('Avocado Pine Nut Bowl')).toBeNull();
    });
    it('resolves every installable menu image, including the sold-out original', () => {
        expect(catalog.dishes).toHaveLength(8);
        for (const dish of catalog.dishes) {
            expect(dishImage(dish.name)).toBe(dish.image);
            expect(dishWeight(dish.name)).toBe(dish.weight);
        }
        expect(dishImageCropped('Green Asparagus Salad')).toBe(true);
        expect(dishImageCropped('Unknown dish')).toBe(false);
    });
    it('sorts exact prices numerically in both directions without mutating data', () => {
        expect(
            sortedDishes(dishes, 'price-asc').map((dish) => dish.id),
        ).toEqual([3, 2, 1]);
        expect(
            sortedDishes(dishes, 'price-desc').map((dish) => dish.id),
        ).toEqual([1, 2, 3]);
        expect(dishes.map((dish) => dish.id)).toEqual([1, 2, 3]);
    });
    it('sorts actual popularity and created dates with a stable fallback', () => {
        expect(sortedDishes(dishes, 'popular').map((dish) => dish.id)).toEqual([
            2, 1, 3,
        ]);
        expect(sortedDishes(dishes, 'newest').map((dish) => dish.id)).toEqual([
            2, 1, 3,
        ]);
    });
    it('uses known reference artwork and never assigns a different dish photo', () => {
        expect(dishImage(' Fresh Strawberry Mint Salad ')).toContain(
            'fresh-strawberry-mint-salad.png',
        );
        expect(dishImage('Unknown dish')).toBeNull();
        expect(dishWeight('Unknown dish')).toBeNull();
        expect(dishWeight('Spring Salad')).toBe('280 g');
    });
});
