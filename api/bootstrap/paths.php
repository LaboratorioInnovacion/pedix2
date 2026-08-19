<?php

declare(strict_types=1);

$apiRoot = dirname(__DIR__);

return [
    'api_root' => $apiRoot,
    'app_root' => $apiRoot . '/app',
    'config_root' => $apiRoot . '/config',
    'storage_root' => $apiRoot . '/storage',
    'logs_root' => $apiRoot . '/storage/logs',
    'public_root' => dirname($apiRoot) . '/public_html',
];
