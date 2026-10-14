# Logging

Logging is **PSR-3** (`Psr\Log\LoggerInterface`). The default implementation,
[`LineLogger`](../src/Logging/LineLogger.php), writes one formatted line per
record to a file or stream. Any PSR-3 logger (Monolog, etc.) can replace it.

## Configuration

[`config/config.php`](../config/config.php) → `logging`:

```php
'logging' => [
    'path'  => dirname(__DIR__) . '/storage/logs/app.log',  // or 'php://stderr'
    'level' => 'debug',   // minimum level written: debug|info|notice|warning|error|…
],
```

`path` may be a file (its directory is created if missing) or a PHP stream.
`level` filters out anything below it.

## Request correlation

The Kernel assigns every request a short `request_id` and includes it in each
log record for that request, so you can trace one request across all its lines.

```
[2026-10-14T06:47:11+00:00] INFO: Login succeeded via ldap {"request_id":"4e48…","provider":"ldap","subject":"devuser","type":"user"}
[2026-10-14T06:47:11+00:00] WARNING: Request failed with 401: Invalid credentials. {"request_id":"c93c…","status":401,…}
```

Messages use PSR-3 `{placeholder}` interpolation from the context array.

## Where the framework logs

| Point | Level | Message |
|---|---|---|
| request start | `debug` | `Request received` (method, path) |
| request end | `info` | `Request handled` (status, `duration_ms`) |
| handled `HttpException` | `info` / `warning` / `error` | `Request failed with {status}` — 4xx info, 401/403 warning, 5xx error |
| uncaught `Throwable` | `error` | `Unhandled exception` (class, file:line, trace) |
| login success | `info` | `Login succeeded via {provider}` |
| login failure | `warning` | `Login failed via {provider}` |
| logout | `info` | `User logged out` |
| policy denial | `warning` | `Authorization denied by policy {policy}` |
| migration applied | `info` | `Migrated {module}/{name}` (via the console) |

The most valuable one: **uncaught exceptions are logged with a full stack trace
before** the client receives its clean 500.

## Logging from your own code

Inject `Psr\Log\LoggerInterface` — it's bound in the container and autowires:

```php
use Psr\Log\LoggerInterface;

final class ProductsWebservice extends Webservice
{
    public function __construct(private readonly LoggerInterface $logger) {}

    #[Route('POST', '/')]
    public function store(): Response
    {
        $this->logger->info('Product created {name}', ['name' => $name]);
        // ...
    }
}
```

(Constructor params default to a `NullLogger` in classes like `LoginWebservice`
so they stay usable standalone; the container supplies the real one in the app.)

## Swapping the logger

Bind your own PSR-3 logger before constructing the app, or pass it in:

```php
$app = new Application($config, $modules, logger: $myMonolog);
```

Everything that logs depends only on `LoggerInterface`, so nothing else changes.
In tests, a `NullLogger` is used by default; pass a capturing logger to assert on
output — see [Testing](testing.md).
