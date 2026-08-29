<?php

declare(strict_types=1);

use VO\Catalog\PublicCatalogController;
use VO\Cart\CartApiController;
use VO\Cart\CartController;
use VO\Cart\CartRepository;
use VO\Cart\CartService;
use VO\Database\PdoConnection;
use VO\Domain\DbIdempotencyStore;
use VO\Http\Request;
use VO\Http\RequestIdMiddleware;
use VO\Http\Router;
use VO\Http\SecurityHeadersMiddleware;
use VO\Orders\OrderPageController;
use VO\Orders\OrderRepository;
use VO\Orders\OrderService;
use VO\Pricing\PricingRepository;
use VO\Pricing\PricingService;
use VO\Payments\MpClient; use VO\Payments\MpWebhookController; use VO\Payments\PaymentRepository; use VO\Payments\PaymentService;
use VO\Payments\ProofStorage; use VO\Settings\SettingsRepository;
use VO\Support\Template;

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    $app = require dirname(__DIR__) . '/api/bootstrap/app.php';
    $response = $app(Request::fromGlobals());

    if (defined('VO_TESTING')) {
        return $response;
    }

    $response->send();
    return;
}

if (getenv('VO_INSTALLER_TESTING') && !defined('VO_TESTING')) define('VO_TESTING', true);
require dirname(__DIR__) . '/api/bootstrap/autoload.php';

$file = getenv('VO_INSTALLED_CONFIG_PATH') ?: dirname(__DIR__) . '/api/config/installed.php';
$cfg = is_file($file) ? require $file : [];
$db = is_array($cfg) ? ($cfg['database'] ?? []) : [];
if (str_starts_with($path, '/api/cart')) {
    if (!is_array($db) || empty($db['dsn'])) { http_response_code(503); echo json_encode(['ok'=>false,'error'=>['code'=>'config','message'=>'Configuration unavailable.']]); return; }
    $pdo = new PDO((string)$db['dsn'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $conn = new PdoConnection((string)$db['dsn'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [], static fn()=> $pdo); $pricing = new PricingService(new PricingRepository($pdo));
    $controller = new CartApiController(new CartService(new CartRepository($pdo), $pricing), new OrderService($conn, new OrderRepository($conn), new DbIdempotencyStore($conn), $pricing));
    $router = new Router();
    $router->get('/api/cart', [$controller,'get']); $router->get('/api/cart/preview', [$controller,'preview']);
    $router->post('/api/cart/items', [$controller,'add']); $router->patch('/api/cart/items/{id}', [$controller,'qty']); $router->delete('/api/cart/items/{id}', [$controller,'delete']);
    $router->post('/api/cart/coupon', [$controller,'coupon']); $router->post('/api/cart/checkout-data', [$controller,'checkout']); $router->post('/api/cart/preview', [$controller,'preview']); $router->post('/api/cart/confirm', [$controller,'confirm']);
    $response = $router->dispatch(Request::fromGlobals()->withAttribute('request_id', Request::newRequestId())); $response->send(); return;
}
if ($path === '/api/webhooks/mercadopago') {
    if ($method !== 'POST') { http_response_code(404); echo json_encode(['received'=>false]); return; }
    if (!is_array($db) || empty($db['dsn'])) { http_response_code(503); echo json_encode(['received'=>false]); return; }
    $pdo = new PDO((string)$db['dsn'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $conn = new PdoConnection((string)$db['dsn'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [], static fn()=> $pdo); $repo = new PaymentRepository($conn);
    $token = $repo->setting($repo->firstBusinessId(), 'mp_access_token') ?? ''; $mp = new MpClient(getenv('VO_MP_BASE_URL') ?: 'https://api.mercadopago.com', $token);
    $res = (new MpWebhookController(new PaymentService($conn, $repo, new OrderRepository($conn), null, Request::newRequestId(), $mp, getenv('VO_PUBLIC_BASE_URL') ?: '')))->handle(Request::fromGlobals()); $res->send(); return;
}

$requestId = Request::newRequestId();
if (!headers_sent()) {
    header(RequestIdMiddleware::HEADER_NAME . ': ' . $requestId);
    foreach (SecurityHeadersMiddleware::HEADERS as $name => $value) header($name . ': ' . $value);
    header('Content-Type: text/html; charset=utf-8');
}

if (!in_array($method, ['GET','POST'], true)) { http_response_code(404); echo 'No encontrado'; return; }
if (!is_array($db) || empty($db['dsn'])) { http_response_code(503); echo 'Configuración no disponible.'; return; }
$pdo = new PDO((string)$db['dsn'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$cartPage = new CartController(new CartService(new CartRepository($pdo), new PricingService(new PricingRepository($pdo))), $pdo, new Template(dirname(__DIR__) . '/api/app/Cart/templates'));
if ($path === '/carrito') { $cartPage->cart(); return; }
if ($path === '/checkout' && $method === 'GET') { $cartPage->checkout(); return; }
if ($path === '/checkout-data' && $method === 'POST') { $cartPage->checkoutData(); return; }
$conn = new PdoConnection((string)$db['dsn'], (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), [], static fn()=> $pdo);
$paymentRepo=new PaymentRepository($conn); $orderRepo=new OrderRepository($conn); $orderPage=new OrderPageController($orderRepo,new Template(dirname(__DIR__).'/api/app/Orders/templates'),$paymentRepo,new PaymentService($conn,$paymentRepo,$orderRepo,null,$requestId,null,getenv('VO_PUBLIC_BASE_URL')?:''),new SettingsRepository($pdo),new ProofStorage(getenv('VO_STORAGE_PATH')?:dirname(__DIR__).'/api/storage'));
if ($method==='GET' && preg_match('#^/pedido/([a-f0-9]{64})$#',$path,$m)) { $orderPage->show($m[1]); return; }
if ($method==='POST' && preg_match('#^/pedido/([a-f0-9]{64})/comprobante$#',$path,$m)) { $orderPage->uploadProof($m[1],$_FILES['proof']??[]); return; }
if ($method !== 'GET') { http_response_code(404); echo 'No encontrado'; return; }
$controller = new PublicCatalogController($pdo, new Template(dirname(__DIR__) . '/api/app/Catalog/templates'));
if ($path === '/') { $controller->home(); return; }
if ($path === '/buscar') { $controller->search((string)($_GET['q'] ?? '')); return; }
if (preg_match('#^/categoria/([a-zA-Z0-9_-]+)$#', $path, $m)) { $controller->category($m[1]); return; }
if (preg_match('#^/producto/([a-zA-Z0-9_-]+)$#', $path, $m)) { $controller->product($m[1]); return; }
$controller->notFound();
