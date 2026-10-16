# Logging

Logging is **PSR-3** (`Psr\Log\LoggerInterface`), backed by **Monolog**, built by
[`LoggerFactory`](../src/Logging/LoggerFactory.php). Instead of one file, logs
are split into three **daily-rotated** channels, each kept for `max_files` days:

| File | Channel | What |
|---|---|---|
| `application-YYYY-MM-DD.log` | application | request lifecycle + your own app logging |
| `security-YYYY-MM-DD.log` | security | logins, login/auth failures, policy denials, 401/403 |
| `error-YYYY-MM-DD.log` | error | every `ERROR` record, from any channel (consolidated) |

The error log is a second handler on every channel, so an error appears **both**
in its own channel's file and in the consolidated error log. The error file is
created lazily — it only appears once something logs at `ERROR`.

Two PSR-3 loggers are wired: the default `LoggerInterface` (application channel)
and [`SecurityLogger`](../src/Logging/SecurityLogger.php) (security channel).
Auth-related classes type-hint `SecurityLogger` so the container autowires the
security channel into them; everything else gets `LoggerInterface`.

## Configuration

[`config/config.php`](../config/config.php) → `logging`:

```php
'logging' => [
    'dir'       => dirname(__DIR__) . '/storage/logs', // directory for the rotating files
    'level'     => 'debug',  // minimum level written: debug|info|notice|warning|error|…
    'max_files' => 14,       // days of history kept per channel (0 = keep all)
],
```

`level` filters out anything below it. To log to a single stream instead of
rotating files (e.g. containers), set `'path' => 'php://stderr'` — that takes
precedence over `dir` and uses one `StreamHandler`.

## Request correlation

The Kernel assigns every request a short `request_id` and includes it in each
log record for that request, so you can trace one request across all its lines.
Monolog's line format is `[time] channel.LEVEL: message {context}` (the channel
name — `application` / `security` — tells you which file it came from):

```
[2026-10-16T12:56:54+00:00] security.INFO: Login succeeded via ldap {"request_id":"4e48…","provider":"ldap","subject":"devuser"}
[2026-10-16T12:56:54+00:00] security.WARNING: Request failed with 401: Invalid credentials. {"request_id":"c93c…","path":"/login/ldap/callback"}
```

Messages use PSR-3 `{placeholder}` interpolation from the context array.

## Where the framework logs

| Point | Channel | Level | Message |
|---|---|---|---|
| request start | application | `debug` | `Request received` (method, path) |
| request end | application | `info` | `Request handled` (status, `duration_ms`) |
| handled `HttpException` 4xx (non-auth) | application | `info` | `Request failed with {status}` |
| handled `HttpException` 401/403 | security | `warning` | `Request failed with {status}` |
| handled `HttpException` 5xx | application (+ error) | `error` | `Request failed with {status}` |
| uncaught `Throwable` | application (+ error) | `error` | `Unhandled exception` (class, file:line, trace) |
| login success | security | `info` | `Login succeeded via {provider}` |
| login failure | security | `warning` | `Login failed via {provider}` |
| logout | security | `info` | `User logged out` |
| policy denial | security | `warning` | `Authorization denied by policy {policy}` |
| OAuth client auth failure | security | `warning` | `OAuth client authentication failed` |
| migration applied | (console stream) | `info` | `Migrated {module}/{name}` |

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

For **security-relevant** events (authentication, authorization, access), inject
[`SecurityLogger`](../src/Logging/SecurityLogger.php) instead — it's the same
PSR-3 API but routes to the security channel:

```php
use bapersbw\Logging\SecurityLogger;

public function __construct(private readonly SecurityLogger $security) {}
// $this->security->warning('Suspicious thing {id}', ['id' => $id]);
```

## Swapping the logger

Bind your own PSR-3 logger before constructing the app, or pass it in:

```php
$app = new Application($config, $modules, logger: $myMonolog);
```

Everything that logs depends only on `LoggerInterface`, so nothing else changes.
In tests, a `NullLogger` is used by default; pass a capturing logger to assert on
output — see [Testing](testing.md).
