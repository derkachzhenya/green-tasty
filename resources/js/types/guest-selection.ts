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
export type GuestMenu = {
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
