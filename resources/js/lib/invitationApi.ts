import type { IssuedInvitation } from '@/types/invitation';

export class InvitationApiError extends Error {
    constructor(
        public status: number,
        public errors: Record<string, string[]> = {},
    ) {
        super('Invitation request failed.');
    }
}

export async function invitationRequest(
    url: string,
    method: 'post' | 'delete',
    signal: AbortSignal,
    body?: { name: string },
): Promise<unknown> {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (cookie)
        headers['X-XSRF-TOKEN'] = decodeURIComponent(
            cookie.slice('XSRF-TOKEN='.length),
        );
    const response = await fetch(url, {
        method: method.toUpperCase(),
        headers,
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        signal,
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as {
            errors?: Record<string, string[]>;
        };
        throw new InvitationApiError(response.status, data.errors);
    }
    return response.status === 204 ? null : response.json();
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
