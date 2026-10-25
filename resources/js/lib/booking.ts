import catalog from '../../data/homepage.json';
import type { BookingRestaurant } from '@/types/booking';

export function bookingGuestError(guests: number, max = 255): string {
    return Number.isInteger(guests) && guests >= 1 && guests <= max
        ? ''
        : `Choose a whole number of guests between 1 and ${max}.`;
}

export const bookingTimes = Array.from(
    { length: 48 },
    (_, index) =>
        `${String(Math.floor(index / 2)).padStart(2, '0')}:${index % 2 ? '30' : '00'}`,
);
export function bookingPhoto(restaurant: BookingRestaurant): string | null {
    const exact = catalog.locations.findIndex(
        (location) => location.address === restaurant.address,
    );
    const index =
        exact >= 0
            ? exact
            : catalog.locations.findIndex(
                  (location) => location.demoSlug === restaurant.slug,
              );
    return index < 0
        ? null
        : `/images/green-tasty/locations/Table image${index ? ` (${index})` : ''}.png`;
}
export function bookingEnd(
    date: string,
    time: string,
    minutes: number,
): { date: string; time: string } {
    // Arithmetic on the restaurant's local clock; no browser timezone conversion.
    const result = new Date(`${date}T${time}:00Z`);
    result.setUTCMinutes(result.getUTCMinutes() + minutes);
    return {
        date: result.toISOString().slice(0, 10),
        time: result.toISOString().slice(11, 16),
    };
}
export function bookingDate(date: string): string {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return date;
    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${date}T12:00:00Z`));
}
