<?php

declare(strict_types=1);

namespace VO\Http;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void {
        $this->routes['GET'][$path] = $handler;
    }

    public function dispatch(Request $request): JsonResponse {
        $method = $request->method();
        $path = $request->path();
        if (isset($this->routes[$method][$path])) {
            return ($this->routes[$method][$path])($request);
        }
        foreach ($this->routes as $routes) {
            if (isset($routes[$path])) {
                return JsonResponse::error('method_not_allowed', 'Method not allowed.', 405, $request->attribute('request_id'));
            }
        }
        return JsonResponse::error('not_found', 'Route not found.', 404, $request->attribute('request_id'));
    }
}
