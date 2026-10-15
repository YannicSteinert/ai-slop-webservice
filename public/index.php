<?php

declare(strict_types=1);

use bapersbw\Application;
use bapersbw\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$modules = require dirname(__DIR__) . '/config/modules.php';

$app = new Application($config, $modules);

// Register policies against custom logic as they are implemented, e.g.:
// $app->gate()->register('users.view', new ViewUsersPolicy());

$app->handle(Request::fromGlobals((string) ($config['base_path'] ?? '')))->send();
