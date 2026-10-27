import catalog from '../../data/homepage.json';
import menuCatalog from '../../data/restaurant-menu.json';
import type { RestaurantListing } from '@/types/restaurant';

export const homepageDishes = catalog.dishes;

export function dishPresentation(name: string, livePrice?: string) {
    const dish = menuCatalog.dishes.find(
        (item) => normalized(item.name) === normalized(name),
    );
    return {
        price: livePrice ?? dish?.price,
        weight: dish?.weight ?? null,
    };
}

export function formatMenuPrice(price?: string): string {
    return price === undefined
        ? 'Price unavailable'
        : `$${price.replace(/\.00$/, '')}`;
}

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

    for (const [index, reference] of catalog.locations.entries()) {
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
            demoLabel:
                restaurant.slug === reference.demoSlug &&
                restaurant.name === `Green Tasty - Location ${index + 1}` &&
                [reference.address, `Demo Address ${index + 1}`].includes(
                    restaurant.address,
                )
                    ? 'Demo location'
                    : null,
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
