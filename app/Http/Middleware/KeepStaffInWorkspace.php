<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class KeepStaffInWorkspace
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $customerArea = $request->is('/', 'book', 'book/*', 'restaurants/*', 'reservations', 'reservations/*', 'dashboard');
        if (! $customerArea || ! ($request->user()?->isStaffAccount() ?? false)) {
            return $next($request);
        }

        if (($request->isMethod('GET') || $request->isMethod('HEAD')) && ! $request->expectsJson()) {
            return redirect()->route('staff.entry')->header('Cache-Control', 'private, no-store');
        }

        // Never execute customer writes or return customer data to a staff session.
        return response()->json(['message' => 'Use your staff workspace to manage restaurant reservations and orders.', 'redirect' => route('staff.entry')], 403)->header('Cache-Control', 'private, no-store');
    }
}
