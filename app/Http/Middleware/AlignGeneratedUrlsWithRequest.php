<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leftover APP_URL (localhost vs 127.0.0.1:8000, or Hostinger still on loopback)
 * makes absolute route()/asset() URLs a different origin than the browser.
 * Session cookies are host-specific, so add-site / activate / image / bulk
 * POSTs look like they "do nothing". Align generated URLs with this request.
 */
class AlignGeneratedUrlsWithRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestRoot = rtrim($request->getSchemeAndHttpHost(), '/');
        $configured = rtrim((string) config('app.url'), '/');

        if ($requestRoot !== '' && $configured !== '' && strcasecmp($requestRoot, $configured) !== 0) {
            URL::forceRootUrl($requestRoot);
            if ($request->secure()) {
                URL::forceScheme('https');
            }
        }

        return $next($request);
    }
}
