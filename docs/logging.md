# Logging

Logging is **PSR-3** (`Psr\Log\LoggerInterface`), backed by **Monolog**. The
logger is built by [`LoggerFactory`](../src/Logging/LoggerFactory.php): a
`Monolog\Logger` writing one line per record to the configured file/stream, with
a `PsrLogMessageProcessor` so `{placeholder}` messages are interpolated. Because
everything depends only on `LoggerInterface`, you can enrich the Monolog stack
(rotating files, syslog, JSON, Sentry…) in the factory, or bind a different
PSR-3 logger entirely, without touching anything else.

## Configuration

[`config/config.php`](../config/config.php) → `logging`:

```php
'logging' => [
    'path'  => dirname(__DIR__) . '/storage/logs/app.log',  // or 'php://stderr'
    'level' => 'debug',   // minimum level written: debug|info|notice|warning|error|…
],
```

`path` is a file (Monolog's `StreamHandler` creates the directory) or a PHP
stream; `level` filters out anything below it.

## Request correlation

The Kernel assigns every request a short `request_id` and includes it in each
log record for that request, so you can trace one request across all its lines.
Monolog's line format is `[time] channel.LEVEL: message {context}`:

```
[2026-10-15T12:56:54+00:00] app.INFO: Login succeeded via ldap {"request_id":"4e48…","provider":"ldap","subject":"devuser"}
[2026-10-15T12:56:54+00:00] app.WARNING: Request failed with 401: Invalid credentials. {"request_id":"c93c…","path":"/login/ldap/callback"}
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
