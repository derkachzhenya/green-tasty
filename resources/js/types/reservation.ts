import type { BookingConfirmation } from './booking';

export type ReservationDetails = Omit<BookingConfirmation, 'status'> & {
    status: string;
};

export type ReservationParticipant = {
    id: number;
    name: string;
    is_owner: boolean;
    response_status: string;
    invitation_expires_at: string | null;
    invitation_status:
        | 'owner'
        | 'not_invited'
        | 'active'
        | 'expired'
        | 'revoked'
        | 'unavailable';
};

export type ConsolidatedDish = {
    menu_item_id: number;
    name: string;
    quantity: number;
    subtotal: string;
};

export type GuestSelection = ConsolidatedDish & { unit_price: string };

export type GuestOrder = {
    reservation_guest_id: number;
    name: string;
    items: GuestSelection[];
    total_quantity: number;
    subtotal: string;
};

export type ConsolidatedSummary = {
    reservation_id: number;
    pre_order_id: number | null;
    items: ConsolidatedDish[];
    guests: GuestOrder[];
    total_quantity: number;
    grand_total: string;
};

export type DashboardOrder = {
    participants: ReservationParticipant[];
    summary: ConsolidatedSummary;
    status: string | null;
    submitted_at: string | null;
};

export type SubmittedGroupOrder = ConsolidatedSummary & {
    status: 'submitted';
    submitted_at: string;
};
