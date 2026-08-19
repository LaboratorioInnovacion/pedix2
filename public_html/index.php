<?php

declare(strict_types=1);

use VO\Http\Request;

$app = require dirname(__DIR__) . '/api/bootstrap/app.php';
$response = $app(Request::fromGlobals());

if (defined('VO_TESTING')) {
    return $response;
}

$response->send();
