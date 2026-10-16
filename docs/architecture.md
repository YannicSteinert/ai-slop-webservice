# Architecture

The framework is intentionally small: a handful of plain classes, no hidden
magic. This page explains how a request becomes a response and what each core
class does.

## The request lifecycle

```
public/index.php
   └─ Application (composition root: wires everything from config)
        └─ Kernel::handle(Request)
             1. assign a request_id, start the session
             2. CORS preflight?  — answer OPTIONS immediately (204 + CORS headers)
             3. resolve identity  — TokenGuard (bearer), else SessionGuard (cookie)
             4. Router::match()    — module → attribute route
             5. enforce policies   — each #[Authorize] checked via the Gate
             6. dispatch           — instantiate the webservice, bind args, invoke
             7. normalise result   — Response / null(204) / value(JSON)
             8. apply CORS headers to the response
             (logging + exception→HTTP mapping wrap the whole flow)
```

These steps live directly in [`src/Kernel.php`](../src/Kernel.php). There is no
middleware layer — it was removed to keep the framework small. To add a new
cross-cutting concern (rate limiting, request metrics, …), you edit the
`process()` method in the Kernel. Read it top to bottom; it is short.

## CORS

Browsers send an `OPTIONS` preflight before a cross-origin request that carries
an `Authorization` header or a JSON body (e.g. `POST /oauth/token` from a
front-end on another origin). The Kernel answers that preflight itself (routes
only declare GET/POST, so it would otherwise 405) and stamps CORS headers on
every response whose request carries an allowed `Origin`. Config
(`config/config.php` → `cors`):

```php
'cors' => [
    'allowed_origins' => ['http://localhost:5173'], // explicit list, or ['*'] for any
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_headers' => null,       // null = reflect the preflight's requested headers
    'allow_credentials' => true,     // echoes the origin back (spec forbids '*' + credentials)
    'max_age' => 600,
],
```

An origin that isn't allow-listed simply receives no CORS headers, so the
browser blocks it. Set `allowed_origins` to your real front-end origins in
production.

## The core classes

| Class | File | Responsibility |
|---|---|---|
| `Application` | [src/Application.php](../src/Application.php) | Composition root. Builds the container, logger, session, guards, gate, router and kernel from config. |
| `Kernel` | [src/Kernel.php](../src/Kernel.php) | Runs the lifecycle above for one request. |
| `Router` | [src/Routing/Router.php](../src/Routing/Router.php) | Holds the module map; loads `#[Route]` attributes (cached); matches a request to a `RouteMatch`. |
| `RouteMatch` | [src/Routing/RouteMatch.php](../src/Routing/RouteMatch.php) | The matched handler + extracted path params + its policies. |
| `Webservice` | [src/Webservice.php](../src/Webservice.php) | Base class every module extends; holds the request/container and offers response helpers. |
| `Container` | [src/Container/Container.php](../src/Container/Container.php) | Tiny DI container with constructor autowiring. |
| `Request` / `Response` | [src/Http/](../src/Http/) | Immutable-ish HTTP value objects. |
| `Gate` | [src/Policy/Gate.php](../src/Policy/Gate.php) | Policy registry; the Kernel asks it to authorize routes. |

## Composition root: `Application`

`Application` is the only place objects are wired together. Its constructor:

```php
new Application(
    array $config,          // from config/config.php
    array $modules,         // from config/modules.php
    ?Session $session = null,     // override for tests (in-memory)
    ?LoggerInterface $logger = null,
);
```

It:

1. creates the `Container` and registers itself into it;
2. builds the `LoggerInterface` (a Monolog logger via [`LoggerFactory`](../src/Logging/LoggerFactory.php));
3. builds the `Session`, `SessionGuard`, `TokenGuard`;
4. binds `AuthManager`, `SessionGuard` and a lazy `Connection` into the container
   (so autowired webservices and repositories can receive them);
5. creates the `Gate` and registers the built-in `authenticated` policy;
6. constructs the `Kernel` with all of the above.

`$app->handle($request)` delegates to the Kernel. `$app->gate()` exposes the gate
so you can register more policies (see [Authorization](authorization.md)).

The front controller is therefore tiny — see [`public/index.php`](../public/index.php):

```php
$app = new Application(
    require __DIR__ . '/../config/config.php',
    require __DIR__ . '/../config/modules.php',
);
$app->handle(Request::fromGlobals())->send();
```

## The dependency container

[`Container`](../src/Container/Container.php) does two things:

- **Explicit bindings** — `bind($id, $factory)` (lazy, memoised) and
  `instance($id, $obj)` (ready object). Used by `Application` for services that
  need construction arguments (e.g. the `Connection` from config).
- **Autowiring** — `get($class)` / `make($class)` reflects a class's constructor
  and resolves each type-hinted parameter recursively, falling back to default
  values. This is how a module's webservice receives its dependencies without
  any wiring: the Kernel calls `container->get(TheWebservice::class)` and the
  container figures out the constructor.

> Rule of thumb: if a class's dependencies are all other classes (or have
> defaults), autowiring just works. If it needs scalars/config, add a `bind()`
> in `Application`.

See [Dependency injection in practice](creating-a-webservice.md#how-dependencies-reach-your-webservice).

## Error handling

Throw a [`HttpException`](../src/Exceptions/HttpException.php) with a status code
anywhere in a handler (or the layers it calls) and the Kernel turns it into a
clean JSON error response with the right status (and any headers, e.g. `Allow`
on a 405). Any *other* uncaught `Throwable` becomes a 500; the message/exception
class are included only when `config['debug']` is true. Both are logged — see
[Logging](logging.md).

```php
use bapersbw\Exceptions\HttpException;

throw new HttpException(404, 'Widget not found.');
throw new HttpException(422, 'Validation failed.', ['X-Hint' => 'name required']);
```

## Why it's shaped this way

- **Modules = microservices.** Each module owns its routes, data access and
  migrations, so features stay isolated and a new app (e.g. onboarding) is just
  a new module.
- **Resolution vs. enforcement are separate.** The Kernel *resolves* who you are
  (identity) but never rejects on its own; rejecting is a *policy* decision. This
  keeps auth flexible and testable.
- **Small over clever.** One orchestrator, plain value objects, a minimal
  container. You can hold the whole thing in your head.
