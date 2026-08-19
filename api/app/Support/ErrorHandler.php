<?php
declare(strict_types=1);
namespace VO\Support;
use Throwable;
use VO\Http\JsonResponse;
use VO\Http\Request;
final class ErrorHandler
{
    public function __construct(private Logger $logger, private bool $debug = false) {}
    public function handle(Request $request, callable $next): JsonResponse
    {
        try { return $next($request); }
        catch (Throwable $e) {
            $requestId = $request->attribute('request_id', Request::newRequestId());
            $this->logger->error('Unhandled runtime error', ['request_id' => $requestId, 'method' => $request->method(), 'path' => $request->path(), 'exception' => $e::class]);
            return JsonResponse::error('internal_error', $this->debug ? 'Runtime error.' : 'The request could not be processed.', 500, $requestId);
        }
    }
}
