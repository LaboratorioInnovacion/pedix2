<?php declare(strict_types=1);
if (getenv('VO_INSTALLER_TESTING')) define('VO_TESTING', true);
require dirname(__DIR__, 2) . '/api/bootstrap/autoload.php';

$requestId = VO\Http\Request::newRequestId();
if (!headers_sent()) {
    header(VO\Http\RequestIdMiddleware::HEADER_NAME . ': ' . $requestId);
    foreach (VO\Http\SecurityHeadersMiddleware::HEADERS as $name => $value) {
        header($name . ': ' . $value);
    }
}

(new VO\Admin\AdminController(dirname(__DIR__, 2), $requestId))->handle();
