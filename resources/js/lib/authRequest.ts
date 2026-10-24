import type { AuthStep, CodeContext } from '@/types/auth-flow';
import { sessionJson } from './sessionJson';

export type AuthResult = {
    next?: AuthStep | 'authenticated';
    redirect?: string;
    challenge?: CodeContext;
};
export async function authRequest(
    url: string,
    signal: AbortSignal,
    body: Record<string, string>,
    method: 'post' | 'patch' = 'post',
): Promise<AuthResult> {
    const value = (await sessionJson(
        url,
        method,
        signal,
        body,
    )) as AuthResult | null;
    if (
        !value ||
        (value.next &&
            ![
                'login',
                'register',
                'forgot',
                'verify',
                'reset',
                'authenticated',
            ].includes(value.next))
    ) {
        throw new Error('Invalid authentication response.');
    }
    const result: AuthResult = { next: value.next };
    if (value.challenge) {
        const context = value.challenge;
        if (
            !['registration', 'recovery'].includes(context.purpose) ||
            typeof context.email !== 'string' ||
            !Number.isFinite(context.resend_after) ||
            typeof context.verified !== 'boolean'
        )
            throw new Error('Invalid challenge response.');
        result.challenge = {
            purpose: context.purpose,
            email: context.email,
            resend_after: Math.max(0, context.resend_after),
            verified: context.verified,
        };
    }
    if (value.next === 'authenticated') {
        if (typeof value.redirect !== 'string')
            throw new Error('Missing redirect.');
        const destination = new URL(value.redirect, window.location.origin);
        if (
            destination.origin !== window.location.origin ||
            destination.username ||
            destination.password
        )
            throw new Error('Invalid redirect.');
        result.redirect =
            destination.pathname + destination.search + destination.hash;
    }
    return result;
}
