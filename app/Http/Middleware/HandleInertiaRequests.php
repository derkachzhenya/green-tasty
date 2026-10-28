<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        // Partial responses omit required page props and must never be prerendered.
        Inertia::disableSsr(fn (): bool => ! config('inertia.ssr.enabled', true)
            || $request->hasHeader(Header::PARTIAL_COMPONENT));

        return parent::handle($request, $next);
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Authenticated pages must not survive account changes as plaintext history snapshots.
        Inertia::encryptHistory($request->user() !== null);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'isStaff' => $request->user()?->isStaffAccount() ?? false,
            ],
            'staffRestaurants' => fn () => $request->user()?->staffRestaurants()->wherePivot('is_active', true)->wherePivot('role', 'waiter')->where('restaurants.is_active', true)->get(['restaurants.id', 'restaurants.name'])->map->only(['id', 'name'])->all() ?? [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
