<?php declare(strict_types=1);
if (getenv('VO_INSTALLER_TESTING')) define('VO_TESTING', true);
require dirname(__DIR__, 2) . '/api/bootstrap/autoload.php';
(new VO\Installer\InstallerController(dirname(__DIR__, 2)))->handle();
