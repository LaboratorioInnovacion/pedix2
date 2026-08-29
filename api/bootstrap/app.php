<?php
declare(strict_types=1);
use VO\Config\Config;
use VO\Cart\CartApiController; use VO\Cart\CartRepository; use VO\Cart\CartService; use VO\Database\PdoConnection; use VO\Domain\DbIdempotencyStore; use VO\Orders\OrderRepository; use VO\Orders\OrderService; use VO\Payments\MpClient; use VO\Payments\MpWebhookController; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService; use VO\Pricing\PricingRepository; use VO\Pricing\PricingService;
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
$jsonRoutes = [
    ['GET', '/health', static fn (Request $request): JsonResponse => JsonResponse::ok(['status' => 'ok', 'app' => $config->get('app.name')])],
];
foreach ($jsonRoutes as [$routeMethod, $routePath, $handler]) {
    $routeMethod === 'POST' ? $router->post($routePath, $handler) : $router->get($routePath, $handler);
}
if (is_file(getenv('VO_INSTALLED_CONFIG_PATH') ?: dirname(__DIR__) . '/config/installed.php')) {
    $installed = require (getenv('VO_INSTALLED_CONFIG_PATH') ?: dirname(__DIR__) . '/config/installed.php'); $db = is_array($installed) ? ($installed['database'] ?? []) : [];
    if (is_array($db) && !empty($db['dsn'])) { $pdo = new PDO((string)$db['dsn'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]); $conn = new PdoConnection((string)$db['dsn'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [], static fn()=> $pdo); $pricing = new PricingService(new PricingRepository($pdo)); $orders = new OrderService($conn, new OrderRepository($conn), new DbIdempotencyStore($conn), $pricing); $cart = new CartApiController(new CartService(new CartRepository($pdo), $pricing), $orders); $repo = new PaymentRepository($conn); $pay = new PaymentService($conn, $repo, new OrderRepository($conn), null, Request::newRequestId(), new MpClient(getenv('VO_MP_BASE_URL') ?: 'https://api.mercadopago.com', $repo->setting($repo->firstBusinessId(), 'mp_access_token') ?? ''), getenv('VO_PUBLIC_BASE_URL') ?: ''); $router->get('/api/cart', [$cart,'get']); $router->get('/api/cart/preview', [$cart,'preview']); $router->post('/api/cart/items', [$cart,'add']); $router->patch('/api/cart/items/{id}', [$cart,'qty']); $router->delete('/api/cart/items/{id}', [$cart,'delete']); $router->post('/api/cart/coupon', [$cart,'coupon']); $router->post('/api/cart/checkout-data', [$cart,'checkout']); $router->post('/api/cart/preview', [$cart,'preview']); $router->post('/api/cart/confirm', [$cart,'confirm']); $router->post('/api/webhooks/mercadopago', [new MpWebhookController($pay),'handle']); }
}
$app = array_reduce(
    array_reverse([new RequestIdMiddleware(), new SecurityHeadersMiddleware()]),
    static fn (callable $next, object $middleware): callable => static fn (Request $request): JsonResponse => $middleware->handle($request, $next),
    static fn (Request $request): JsonResponse => $router->dispatch($request)
);
return static fn (Request $request): JsonResponse => $errors->handle($request, $app);
