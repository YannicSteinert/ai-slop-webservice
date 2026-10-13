# Webservice

A small, attribute-routed PHP web service framework. Modules are self-contained
webservice classes; routing, middleware, and policies are wired once and stay
out of your way.

## Request lifecycle

```
public/index.php
   └─ Kernel::handle(Request)
        ├─ Router::match()            resolve module → match attribute route
        ├─ Pipeline (middleware)      auth, policies, logging, …  ← extension seam
        └─ handler invocation         bind args from path + container → Response
```

The URL is read as `/{module}/{...rest}`:

- the **first segment** selects the module via `config/modules.php`
  (`'users' => UsersWebservice::class`);
- the **remainder** is matched against that class's `#[Route]` attributes.

## Routing

Routes are declared as attributes on webservice methods. Paths are relative to
the module. Parameters use `{name}` and may carry a regex constraint `{name:\d+}`;
multiple parameters per route are supported.

```php
final class UsersWebservice extends Webservice
{
    #[Route('GET', '/{user_id}/show')]
    public function show(int $user_id): Response
    {
        return $this->ok(['id' => $user_id]);
    }

    #[Route('GET', '/{group_id}/members/{user_id}')]
    public function member(int $group_id, int $user_id): Response { /* … */ }
}
```

Handler arguments are bound **by name** from the path (cast to the declared
scalar type), plus `Request` by type hint and any other class from the container.
A handler may return a `Response`, `null` (→ 204), or any value (→ JSON).

## Adding a module

1. Create `src/Modules/Foo/FooWebservice.php` extending
   `bapersbw\Webservice\Webservice` with `#[Route]` methods.
2. Register it in `config/modules.php`: `'foo' => FooWebservice::class`.

That's the whole contract.

## Policies (add later, additively)

Authorization is declarative and off by default:

1. Implement `bapersbw\Policy\PolicyInterface`.
2. Register it on the `Gate` in `public/index.php`:
   `$container->get(Gate::class)->register('users.view', ViewUsersPolicy::class);`
3. Annotate routes (or the whole class) with `#[Authorize('users.view')]`.

`AuthorizationMiddleware` (already installed) enforces the policies on the
matched route. Routes with no `#[Authorize]` are unaffected. Authentication
middleware that resolves the current user would run before it and store the user
via `$request->withAttribute('user', …)`.

## Login

`LoginWebservice` (`/login`) selects a provider (SAML or LDAP) and redirects.
The branching is in place; the SAML/LDAP flows themselves are stubbed in
`redirectToProvider()` and configured under `config/config.php` → `auth`.

## Running

```bash
composer dump-autoload
php -S 127.0.0.1:8080 -t public
```

Behind Apache/nginx, route all non-file requests to `public/index.php`:

```apache
# public/.htaccess
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```

```nginx
location / { try_files $uri /index.php$is_args$args; }
```

## Layout

| Path | Responsibility |
|---|---|
| `src/Attributes/` | `Route`, `Authorize` |
| `src/Http/` | `Request`, `Response`, `JsonResponse`, `RedirectResponse`, `HttpMethod` |
| `src/Routing/` | `Router`, `AttributeRouteLoader`, `RouteDefinition`, `RouteMatch`, `ArgumentResolver` |
| `src/Module/` | `ModuleRegistry` |
| `src/Container/` | autowiring DI container (`ContainerInterface`) |
| `src/Middleware/` | `MiddlewareInterface`, `Pipeline` |
| `src/Policy/` | `PolicyInterface`, `Gate`, `AuthorizationMiddleware` |
| `src/Webservice/` | abstract `Webservice` base class |
| `src/Modules/` | the modules themselves |
| `src/Kernel.php` | orchestrator |
```
