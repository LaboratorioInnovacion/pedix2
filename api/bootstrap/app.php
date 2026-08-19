<?php
declare(strict_types=1);
use VO\Config\Config;
use VO\Http\JsonResponse;
use VO\Http\Request;
use VO\Http\RequestIdMiddleware;
use VO\Http\Router;
use VO\Http\SecurityHeadersMiddleware;
use VO\Support\ErrorHandler;
use VO\Support\Logger;
$paths = require __DIR__ . '/paths.php';
require __DIR__ . '/autoload.php';
$config = Config::load($paths, ['app', 'database']);
$errors = new ErrorHandler(new Logger($paths['logs_root'] . '/app.log'), (bool) $config->get('app.debug', false));
$router = new Router();
$router->get('/health', static fn (Request $request): JsonResponse => JsonResponse::ok(['status' => 'ok', 'app' => $config->get('app.name')]));
$app = array_reduce(
    array_reverse([new RequestIdMiddleware(), new SecurityHeadersMiddleware()]),
    static fn (callable $next, object $middleware): callable => static fn (Request $request): JsonResponse => $middleware->handle($request, $next),
    static fn (Request $request): JsonResponse => $router->dispatch($request)
);
return static fn (Request $request): JsonResponse => $errors->handle($request, $app);
