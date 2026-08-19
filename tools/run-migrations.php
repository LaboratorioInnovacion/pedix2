<?php declare(strict_types=1);
require __DIR__ . '/../api/bootstrap/autoload.php';
use VO\Config\Config; use VO\Database\MigrationRunner; use VO\Database\PdoConnection;

$options = getopt('', ['config::', 'path::']);
$paths = require __DIR__ . '/../api/bootstrap/paths.php';
if (isset($options['config'])) {
    $paths['config_root'] = (string) $options['config'];
}
$migrations = (string) ($options['path'] ?? $paths['api_root'] . '/database/migrations');

$config = Config::load($paths, ['app', 'database']);
$runner = new MigrationRunner(new PdoConnection((string) $config->get('database.dsn'), (string) $config->get('database.user'), (string) $config->get('database.password')), $migrations);

$ran = $runner->run();
echo count($ran) === 0 ? 'No pending migrations.' . PHP_EOL : 'Applied migrations:' . PHP_EOL . implode(PHP_EOL, $ran) . PHP_EOL;
