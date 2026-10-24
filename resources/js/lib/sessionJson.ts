export class SessionRequestError extends Error {
    constructor(
        public status: number,
        public errors: Record<string, string[]> = {},
        public retryAfter = 0,
        public reason = '',
    ) {
        super('Authenticated request failed.');
    }
}

/** Same-origin session authentication and Laravel's encrypted XSRF cookie. */
export async function sessionJson(
    url: string,
    method: 'post' | 'patch' | 'delete',
    signal: AbortSignal,
    body?: Record<string, unknown>,
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
        signal,
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (!response.ok) {
        const data = (await response.json().catch(() => null)) as {
            errors?: Record<string, unknown>;
            reason?: unknown;
        } | null;
        const errors = Object.fromEntries(
            Object.entries(data?.errors ?? {}).map(([key, value]) => [
                key,
                Array.isArray(value)
                    ? value.filter(
                          (entry): entry is string => typeof entry === 'string',
                      )
                    : [],
            ]),
        );
        const retryAfter = Number(response.headers.get('Retry-After'));
        throw new SessionRequestError(
            response.status,
            errors,
            Number.isFinite(retryAfter) ? Math.max(0, retryAfter) : 0,
            typeof data?.reason === 'string' ? data.reason : '',
        );
    }
    return response.status === 204 ? null : response.json();
}
