import type { PreOrderTiming } from '@/composables/usePreOrderClock';
import type { ConsolidatedSummary, GuestSelection } from './reservation';
import type { RestaurantReview } from './restaurant-review';
export type OwnerGroupParticipant = {
    id: number;
    name: string;
    is_owner: boolean;
    confirmed: boolean;
    revoked: boolean;
    editable: boolean;
    can_remove: boolean;
    items: (GuestSelection & {
        id: number;
        // Client-only identity retained when a quantity edit creates a new revision.
        display_key?: number;
        notes: string | null;
        decision?: string;
    })[];
    subtotal: string;
};
export type OwnerGroupOrder = {
    cancelled_items?: {
        id: number;
        name: string;
        participant: string;
        quantity: number;
        unit_price: string;
        notes: string | null;
    }[];
    restaurant_review?: RestaurantReview;
    timing?: PreOrderTiming;
    active_participants?: number;
    removed_participants?: number;
    reservation_id: number;
    version: string;
    notification_event_id?: number;
    status: string | null;
    submitted_at: string | null;
    editable: boolean;
    can_submit: boolean;
    participants: OwnerGroupParticipant[];
    summary: ConsolidatedSummary;
};
