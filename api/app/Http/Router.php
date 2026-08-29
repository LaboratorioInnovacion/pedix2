<?php

declare(strict_types=1);

namespace VO\Http;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void {
        $this->routes['POST'][$path] = $handler;
    }
    public function patch(string $path, callable $handler): void { $this->routes['PATCH'][$path] = $handler; }
    public function delete(string $path, callable $handler): void { $this->routes['DELETE'][$path] = $handler; }

    public function dispatch(Request $request): JsonResponse {
        $method = $request->method();
        $path = $request->path();
        if (isset($this->routes[$method][$path])) {
            return ($this->routes[$method][$path])($request);
        }
        foreach ($this->routes[$method] ?? [] as $route => $handler) {
            $pattern = '#^' . str_replace('\\{id\\}', '(\\d+)', preg_quote($route, '#')) . '$#';
            if (preg_match($pattern, $path, $m)) return $handler($request->withAttribute('id', (int)$m[1]));
        }
        foreach ($this->routes as $routes) {
            if (isset($routes[$path])) {
                return JsonResponse::error('method_not_allowed', 'Method not allowed.', 405, $request->attribute('request_id'));
            }
            foreach (array_keys($routes) as $route) {
                $pattern = '#^' . str_replace('\\{id\\}', '(\\d+)', preg_quote($route, '#')) . '$#';
                if (preg_match($pattern, $path)) return JsonResponse::error('method_not_allowed', 'Method not allowed.', 405, $request->attribute('request_id'));
            }
        }
        return JsonResponse::error('not_found', 'Route not found.', 404, $request->attribute('request_id'));
    }
}
