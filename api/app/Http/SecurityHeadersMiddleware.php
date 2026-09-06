<?php

declare(strict_types=1);

namespace VO\Http;

final class SecurityHeadersMiddleware implements Middleware
{
    public const HEADERS = [
        'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=()',
    ];

    public function handle(Request $request, callable $next): JsonResponse
    {
        $response = $next($request);
        foreach (self::HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
