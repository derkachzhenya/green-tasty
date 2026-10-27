export type BookingRestaurant = {
    id: number;
    name: string;
    slug: string;
    address: string;
    phone?: string | null;
};
export type BookingTable = { id: number; name: string; seats: number };
export type BookingSearchTable = BookingTable & {
    restaurant: BookingRestaurant;
    slots: { time: string; available: boolean }[];
};
export type BookingFilters = {
    restaurant: string;
    date: string;
    time: string;
    guest_count: number;
    duration_minutes?: number;
};
export type BookingRules = {
    timezone: string;
    duration_minutes: number;
    allowed_durations?: number[];
    slot_minutes: number;
    min_date: string;
    max_date: string;
    default_date: string;
    max_guests?: number;
};
export type BookingConfirmation = {
    id: number;
    code: string;
    status: 'draft' | 'confirmed' | 'cancelled' | 'completed';
    guest_count: number;
    restaurant: Pick<BookingRestaurant, 'name' | 'slug' | 'address'>;
    table: Pick<BookingTable, 'name' | 'seats'> | null;
    date: string;
    time: string;
    end_date: string;
    end_time: string;
    timezone: string;
};
