<?php

declare(strict_types=1);

namespace VO\Http;

final class SecurityHeadersMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): JsonResponse
    {
        return $next($request)
            ->withHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
    }
}
