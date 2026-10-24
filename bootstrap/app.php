<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            AuthenticateSession::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'token', 'password', 'password_confirmation', 'current_password']);
        $exceptions->respond(function (Response $response): Response {
            if (request()->routeIs('auth.*', 'login*', 'register*', 'password.*', 'verification.*')) {
                $response->headers->set('Cache-Control', 'private, no-store');
                $response->headers->set('Referrer-Policy', 'no-referrer');
            }

            return $response;
        });
        $exceptions->render(function (NotFoundHttpException $exception, Request $request): ?Response {
            if ($request->routeIs('restaurants.show') && ! $request->expectsJson()) {
                // Missing bindings can fail before the Inertia middleware shares auth props.
                return Inertia::render('restaurants/NotFound', [
                    'auth' => ['user' => $request->user()],
                ])->toResponse($request)->setStatusCode(404);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
