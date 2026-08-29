<?php declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);
if (is_file($file)) return false;
if ($path === '/install/' || $path === '/install' || isset($_GET['step'])) {
    require __DIR__ . '/install/index.php';
    return true;
}
if (str_starts_with($path, '/admin/')) {
    require __DIR__ . '/admin/index.php';
    return true;
}
require __DIR__ . '/index.php';
return true;
