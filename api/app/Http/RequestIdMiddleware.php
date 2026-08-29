<?php

declare(strict_types=1);

namespace VO\Http;

final class RequestIdMiddleware implements Middleware
{
    public const HEADER_NAME = 'X-Request-Id';

    public function handle(Request $request, callable $next): JsonResponse
    {
        $requestId = $request->attribute('request_id', Request::newRequestId());
        return $next($request->withAttribute('request_id', $requestId))->withRequestId($requestId)->withHeader(self::HEADER_NAME, $requestId);
    }
}
