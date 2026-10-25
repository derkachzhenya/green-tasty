import type { ConsolidatedSummary, GuestSelection } from './reservation';
export type OwnerGroupParticipant = {
    id: number;
    name: string;
    is_owner: boolean;
    confirmed: boolean;
    revoked: boolean;
    editable: boolean;
    can_remove: boolean;
    items: (GuestSelection & { id: number; notes: string | null })[];
    subtotal: string;
};
export type OwnerGroupOrder = {
    reservation_id: number;
    version: string;
    status: string | null;
    submitted_at: string | null;
    editable: boolean;
    can_submit: boolean;
    participants: OwnerGroupParticipant[];
    summary: ConsolidatedSummary;
};
