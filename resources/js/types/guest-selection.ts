import type { MenuDish } from './restaurant';

export type GuestSelectionItem = {
    menu_item_id: number;
    name: string;
    quantity: number;
    unit_price: string;
    subtotal: string;
};
export type GuestSelectionSummary = {
    items: GuestSelectionItem[];
    total_quantity: number;
    subtotal: string;
};
export type GuestContext = {
    restaurant: string;
    address: string;
    table: string;
    date: string;
    time: string;
    end_date: string;
    end_time: string;
    guests: number;
};
export type GuestCatalogDish = MenuDish & {
    available: boolean;
    created_at: string | null;
    popularity: number;
    sort_order: number;
};
export type GuestMenu = {
    context: GuestContext;
    guest_state: {
        name_confirmed: boolean;
        submitted_at: string | null;
        version: number;
    };
    catalog: { id: number; name: string; dishes: GuestCatalogDish[] }[];
    invitation: {
        restaurant: { name: string };
        reservation: { reserved_at: string };
        guest: { name: string };
        menu_categories: { id: number; name: string; dishes: MenuDish[] }[];
    };
    selections: GuestSelectionSummary;
    order: {
        status: string | null;
        submitted_at: string | null;
        editable: boolean;
    };
    timezone: string;
};
export type SelectionInput = { menu_item_id: number; quantity: number };
export type DraftDish = GuestSelectionItem & { available: boolean };
