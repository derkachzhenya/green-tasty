import type { IssuedInvitation } from '@/types/invitation';
import { sessionJson } from './sessionJson';

export { SessionRequestError as InvitationApiError } from './sessionJson';

export async function invitationRequest(
    url: string,
    method: 'post' | 'delete',
    signal: AbortSignal,
    body?: { name: string },
): Promise<unknown> {
    return sessionJson(url, method, signal, body);
}

export function issuedInvitation(
    data: unknown,
    origin: string,
): IssuedInvitation {
    const value = data as Partial<IssuedInvitation> | null;
    if (
        !value ||
        !Number.isSafeInteger(value.guest_id) ||
        (value.guest_id ?? 0) < 1 ||
        typeof value.name !== 'string' ||
        typeof value.expires_at !== 'string' ||
        !Number.isFinite(Date.parse(value.expires_at)) ||
        typeof value.invitation_url !== 'string'
    ) {
        throw new Error('Invalid invitation response.');
    }
    const url = new URL(value.invitation_url);
    if (
        url.origin !== origin ||
        !['http:', 'https:'].includes(url.protocol) ||
        url.username ||
        url.password ||
        url.search ||
        url.hash ||
        !/^\/invitations\/[a-f0-9]{64}$/.test(url.pathname)
    ) {
        throw new Error('Invalid invitation response.');
    }
    // Retain only the supported response fields, never arbitrary server data.
    return {
        guest_id: value.guest_id as number,
        name: value.name,
        expires_at: value.expires_at,
        invitation_url: value.invitation_url,
    };
}

export function formatInvitationExpiration(
    value: string,
    timezone: string,
): string {
    try {
        return new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: timezone,
        }).format(new Date(value));
    } catch {
        return 'Expiration unavailable';
    }
}
