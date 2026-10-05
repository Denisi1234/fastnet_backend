<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway/Heroku-style platforms terminate TLS at the edge and forward
        // plain HTTP with X-Forwarded-Proto/For. Without trusting the proxy,
        // generated URLs come out http:// (mixed content, broken images) and
        // $request->ip() is the load-balancer IP, which turns the shared
        // `throttle` bucket into one global limit for every user. Trusting all
        // proxies is the standard posture here because the container only ever
        // receives traffic from the platform's own routing layer.
        $middleware->trustProxies(at: '*');

        // API-only backend: there is no web login page, so the framework
        // default guest redirect (route('login')) explodes with
        // "Route [login] not defined" (HTTP 500) on every unauthenticated API
        // call. Redirect nowhere instead — unauthenticated API calls become a
        // clean 401 JSON. This was the blanket 500 behind every dead
        // admin-portal button.
        $middleware->redirectGuestsTo(fn () => null);

        // Registered unconditionally: the shim disables itself in production
        // from inside handle(), where the container is fully built. It cannot
        // be gated here — this closure runs before base bindings exist, so any
        // app()->isProduction() call fails the boot outright.
        $middleware->prepend(\App\Http\Middleware\ForceCorsDev::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // API has no web login page, so the framework default of redirecting
        // guests to route('login') explodes with "Route [login] not defined"
        // (HTTP 500) on every unauthenticated API call that does not send
        // Accept: application/json. Answer 401 JSON for all of api/* instead.
        // This was the blanket 500 behind every dead admin-portal button.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // A database failure must never reach the browser as a PDO message.
        // The raw text discloses the host, port, database name, table and
        // column names and the shape of the query — and with APP_DEBUG=true it
        // was being rendered straight into the signup form's alert box. Answer
        // with an opaque 503 instead and keep the detail in the log.
        $exceptions->render(function (QueryException $e, Request $request) {
            Log::error(
                'Database failure on '.$request->method().' '.$request->path().': '.$e->getMessage(),
            );

            return response()->json([
                'message' => 'We could not reach our database right now. Please try again in a moment.',
            ], 503);
        });
    })->create();
