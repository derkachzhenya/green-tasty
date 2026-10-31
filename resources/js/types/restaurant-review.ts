export type RestaurantNotification = {
    reservation_id: number;
    kind: string;
    revision: number;
};

export type ReviewItem = {
    id: number;
    menu_item_id: number;
    name: string;
    quantity: number;
    unit_price: string;
    total: string;
    notes: string | null;
    decision: 'pending' | 'confirmed' | 'rejected';
    published: boolean;
    superseded: boolean;
    cancelled?: boolean;
};
export type RestaurantReview = {
    timing?: PreOrderTiming;
    version: string;
    revision: number;
    status: string;
    editable: boolean;
    can_submit: boolean;
    participants: {
        id: number;
        name: string;
        is_owner: boolean;
        items: ReviewItem[];
    }[];
    totals: {
        requested: string;
        confirmed: string;
        rejected: string;
        pending: string;
    };
    events: { kind: string; revision: number; audience: string }[];
    can_correct?: boolean;
    catalog?: { id: number; name: string; price: string }[];
};
export type WaiterReservation = {
    id: number;
    code: string;
    owner_name: string;
    date: string;
    time: string;
    end_time: string;
    duration_minutes?: number;
    guest_count: number;
    active_participants: number;
    table_id: number | null;
    restaurant: { name: string; address: string };
    table: { name: string; seats: number } | null;
    phase: 'upcoming' | 'in_progress' | 'completed' | 'canceled';
    version: string;
    pre_orders: number;
    has_pre_order: boolean;
    has_service_order?: boolean;
    needs_review: boolean;
    updated: boolean;
    can_edit: boolean;
    can_cancel: boolean;
};

export type ServiceOrderLine = {
    line_key: string;
    menu_item_id: number;
    name: string;
    quantity: number;
    unit_price: string;
    notes: string | null;
    total: string;
};
export type ServiceOrder = {
    exists: boolean;
    revision: number;
    editable: boolean;
    phase: string;
    blocked_reason?: string | null;
    version: string;
    items: ServiceOrderLine[];
    total: string;
    catalog: {
        id: number;
        name: string;
        price: string;
        is_available: boolean;
    }[];
};
import type { PreOrderTiming } from '@/composables/usePreOrderClock';
