<?php

declare(strict_types=1);

define('VO_TESTING', true);

require __DIR__ . '/../api/bootstrap/autoload.php';
require __DIR__ . '/../tests/TestCase.php';

$files = glob(__DIR__ . '/../tests/*Test.php') ?: [];
$failures = 0;
$tests = 0;

foreach ($files as $file) {
    require_once $file;
    $class = 'Tests\\' . basename($file, '.php');
    $case = new $class();
    foreach (get_class_methods($case) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $tests++;
        try {
            $case->$method();
            echo 'PASS ' . $class . '::' . $method . PHP_EOL;
        } catch (Throwable $e) {
            $failures++;
            echo 'FAIL ' . $class . '::' . $method . ' - ' . $e->getMessage() . PHP_EOL;
        }
    }
}

echo PHP_EOL . $tests . ' tests, ' . $failures . ' failures' . PHP_EOL;
exit($failures === 0 ? 0 : 1);
