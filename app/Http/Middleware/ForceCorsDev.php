<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceCorsDev
{
    /**
     * Force CORS headers on every response.
     * This ensures requests from file:// (Origin: null) and any other origin always work.
     *
     * Dev-only: in production this is a pass-through. config/cors.php already
     * answers preflights and sets headers there, so the shim's global OPTIONS
     * short-circuit and forced headers must not run. The check lives here and
     * not in bootstrap/app.php because that closure runs before container
     * bindings exist — reading the environment there fails the boot.
     */
    public function handle(Request $request, Closure $next)
    {
        if (app()->isProduction()) {
            return $next($request);
        }

        // Handle preflight OPTIONS requests immediately
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', '*')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', '*');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
