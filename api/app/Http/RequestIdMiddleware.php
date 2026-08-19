<?php

declare(strict_types=1);

namespace VO\Http;

final class RequestIdMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): JsonResponse
    {
        $requestId = $request->attribute('request_id', Request::newRequestId());
        return $next($request->withAttribute('request_id', $requestId))->withRequestId($requestId)->withHeader('X-Request-Id', $requestId);
    }
}
