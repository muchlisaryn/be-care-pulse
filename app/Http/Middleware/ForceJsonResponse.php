<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paksa setiap request pada grup `api` untuk selalu meng-expect JSON.
 *
 * Tanpa ini, request tak terautentikasi yang tidak mengirim header
 * `Accept: application/json` akan membuat middleware auth memanggil
 * `route('login')` (yang tidak ada di app API-only) → RouteNotFoundException
 * "Route [login] not defined" → respons 500, bukan 401. Dengan memaksa
 * Accept JSON, `expectsJson()` selalu true sehingga handler 401 di
 * bootstrap/app.php yang berjalan.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
