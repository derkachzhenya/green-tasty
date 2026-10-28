export type ReservationPhase =
    | 'upcoming'
    | 'in_progress'
    | 'completed'
    | 'canceled';
export type ReservationCounts = Record<ReservationPhase | 'all', number>;

import type { BookingConfirmation } from './booking';

export type ReservationDetails = Omit<BookingConfirmation, 'status'> & {
    status: string;
};

export type OwnedReservation = ReservationDetails & {
    active_participants?: number;
    removed_participants?: number;
    pre_order_status: string | null;
    has_pre_order_updates?: boolean;
    can_cancel?: boolean;
    cancel_version?: string;
    phase?: ReservationPhase;
    submitted_pre_orders?: number;
    can_invite?: boolean;
    can_add_pre_order?: boolean;
    can_view_group?: boolean;
    feedback_action?: 'leave' | 'update' | null;
};

export type MyReservationsPage = {
    data: OwnedReservation[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
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
