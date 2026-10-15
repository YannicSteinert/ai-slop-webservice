<?php

declare(strict_types=1);

/**
 * Standalone diagnostics — open this file directly in the browser:
 *   https://…/11790617/ws/public/_debug.php
 *
 * It bypasses routing (so no rewrite/redirect is involved) and reports the
 * things that usually break a deploy: the sqlsrv driver, database
 * connectivity, log-dir writability, and how the base path resolves.
 *
 * It only runs when config 'debug' => true, and never prints the DB password.
 * Delete it (or keep debug off) in production.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use bapersbw\Database\Connection;
use bapersbw\Http\Request;

$config = require dirname(__DIR__) . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

if (empty($config['debug'])) {
    http_response_code(403);
    echo "Diagnostics disabled. Set 'debug' => true in config/config.php to use this.\n";
    exit;
}

function row(string $k, string $v): void
{
    printf("%-22s %s\n", $k, $v);
}

echo "== PHP ==\n";
row('php_version', PHP_VERSION);
row('pdo_sqlsrv', extension_loaded('pdo_sqlsrv') ? 'loaded' : 'MISSING (required for MSSQL)');
row('pdo_drivers', implode(', ', PDO::getAvailableDrivers()));

echo "\n== Request / base path ==\n";
row('REQUEST_URI', (string) ($_SERVER['REQUEST_URI'] ?? ''));
row('SCRIPT_NAME', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
row('PATH_INFO', (string) ($_SERVER['PATH_INFO'] ?? ''));
row('HTTPS', (string) ($_SERVER['HTTPS'] ?? '(not set)'));
row('config base_path', (string) ($config['base_path'] ?? ''));
$req = Request::fromGlobals((string) ($config['base_path'] ?? ''));
row('resolved basePath', $req->basePath);
row('resolved path', $req->path);
row('url(/login) would be', $req->url('/login'));

echo "\n== Logging ==\n";
$logPath = (string) ($config['logging']['path'] ?? '');
$logDir = $logPath !== '' ? dirname($logPath) : '';
row('log path', $logPath);
row('log dir', $logDir);
row('log dir exists', is_dir($logDir) ? 'yes' : 'NO');
row('log dir writable', (is_dir($logDir) && is_writable($logDir)) ? 'yes' : 'NO  <-- this is why there are no logs');
if (is_file($logPath)) {
    row('log file writable', is_writable($logPath) ? 'yes' : 'NO');
    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    echo "\n-- last 25 log lines --\n";
    echo $lines === [] ? "(empty)\n" : implode("\n", array_slice($lines, -25)) . "\n";
} else {
    row('log file', 'does not exist yet');
}

echo "\n== Database ==\n";
$db = (array) ($config['database'] ?? []);
row('driver', (string) ($db['driver'] ?? ''));
row('host', (string) ($db['host'] ?? ''));
row('port', (string) ($db['port'] ?? ''));
row('database', (string) ($db['database'] ?? ''));
row('username', (string) ($db['username'] ?? ''));
try {
    (new Connection($db))->pdo();
    row('connection', 'OK');
} catch (\Throwable $e) {
    row('connection', 'FAILED');
    row('error', $e->getMessage());
}

echo "\n== Modules ==\n";
foreach ((array) (require dirname(__DIR__) . '/config/modules.php') as $name => $class) {
    row((string) $name, (string) $class);
}

echo "\nDone. Remove this file or set debug=false before going live.\n";
