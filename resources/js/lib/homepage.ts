import catalog from '../../data/homepage.json';
import type { RestaurantListing } from '@/types/restaurant';

export const homepageDishes = catalog.dishes;

export type HomepageLocation = {
    restaurant: RestaurantListing;
    address: string;
    image: string | null;
    referenceAddress: string | null;
    demoLabel: string | null;
};

const normalized = (value: string) => value.trim().toLowerCase();

export function homepageLocations(
    restaurants: RestaurantListing[],
): HomepageLocation[] {
    const used = new Set<number>();
    const locations: HomepageLocation[] = [];

    for (const reference of catalog.locations) {
        const exact = restaurants.find(
            (restaurant) =>
                !used.has(restaurant.id) &&
                normalized(restaurant.address) ===
                    normalized(reference.address),
        );
        // Slugs may select reference artwork, never a different booking address.
        const restaurant =
            exact ??
            restaurants.find(
                (restaurant) =>
                    !used.has(restaurant.id) &&
                    restaurant.slug === reference.demoSlug,
            );
        if (!restaurant) continue;
        used.add(restaurant.id);
        locations.push({
            restaurant,
            address: restaurant.address,
            image: reference.image,
            referenceAddress: reference.address,
            demoLabel: exact ? null : `Demo: ${restaurant.address}`,
        });
    }

    // Preserve navigation for real locations beyond the three presentation references.
    for (const restaurant of restaurants) {
        if (used.has(restaurant.id)) continue;
        locations.push({
            restaurant,
            address: restaurant.address,
            image: null,
            referenceAddress: null,
            demoLabel: null,
        });
    }

    return locations;
}

export function featuredPrice(
    name: string,
    prices: Record<string, string> | undefined,
): string | undefined {
    return Object.entries(prices ?? {}).find(
        ([dish]) => normalized(dish) === normalized(name),
    )?.[1];
}
