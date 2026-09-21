<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.force_https')) {
            return $next($request);
        }

        if (! $request->isSecure()) {
            if (! $request->isMethodSafe()) {
                abort(400, 'This form must be submitted over a secure HTTPS connection.');
            }

            return redirect()->secure($request->getRequestUri());
        }

        $response = $next($request);
        $hstsMaxAge = (int) config('app.https_hsts_max_age');

        if ($hstsMaxAge > 0) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.$hstsMaxAge);
        }

        return $response;
    }
}
