# Authorization & Policies

Authorization is **declarative and opt-in**. A route is unguarded unless it
carries an `#[Authorize]` attribute. The Kernel enforces those attributes by
asking the [`Gate`](../src/Policy/Gate.php) to evaluate each named policy before
the handler runs; a denial becomes `HttpException(403)`.

```
#[Authorize('name')] on a route  ──►  Kernel  ──►  Gate::allows('name', request, args)  ──►  bool
                                                            │ false
                                                            ▼
                                                      403 Forbidden
```

## The `#[Authorize]` attribute

[`#[Authorize]`](../src/Attributes/Authorize.php) names a policy and optional
arguments. It is repeatable and may sit on a **method** (that route) or the
**class** (every route in it); class + method policies are combined.

```php
use bapersbw\Attributes\Authorize;

#[Authorize('authenticated')]                 // policy name only
#[Authorize('authenticated', 'user', 'admin')] // name + arguments
#[Route('POST', '/')]
public function store(): Response { ... }
```

## Policies

A policy implements [`PolicyInterface`](../src/Policy/PolicyInterface.php):

```php
interface PolicyInterface
{
    /** @param list<string|int|float|bool> $arguments  the extra #[Authorize] args */
    public function authorize(Request $request, array $arguments): bool;
}
```

It reads whatever it needs from the request (the resolved `identity`, path
params, etc.) and returns `true` to allow. Example — only the owner may act:

```php
<?php

namespace bapersbw\Modules\Products\Policies;

use bapersbw\Auth\Identity;
use bapersbw\Http\Request;
use bapersbw\Policy\PolicyInterface;

final class OwnsProductPolicy implements PolicyInterface
{
    public function authorize(Request $request, array $arguments): bool
    {
        $identity = $request->attribute('identity');
        if (!$identity instanceof Identity) {
            return false;
        }

        $match = $request->attribute('route');          // the RouteMatch
        $ownerId = $match->parameters['owner_id'] ?? null;

        return $identity->id === $ownerId;
    }
}
```

## Registering a policy

Register it on the gate. The composition root exposes it via `$app->gate()`;
do this in [`public/index.php`](../public/index.php) (and in tests that need it):

```php
$app = new Application($config, $modules);
$app->gate()->register('products.owns', new OwnsProductPolicy());
```

Then guard the route:

```php
#[Authorize('products.owns')]
#[Route('PUT', '/{owner_id}/products/{id}')]
public function update(int $owner_id, int $id): Response { ... }
```

> A route that names a policy which was never registered fails closed:
> the gate throws `HttpException(500)` rather than silently allowing.

## The built-in `authenticated` policy

[`AuthenticatedPolicy`](../src/Policy/AuthenticatedPolicy.php) is registered for
you (as `authenticated`). It passes when there is an identity, optionally
constrained by type and/or roles given as arguments:

```php
#[Authorize('authenticated')]                    // any user or client
#[Authorize('authenticated', 'user')]            // humans only
#[Authorize('authenticated', 'client')]          // inward services only
#[Authorize('authenticated', 'user', 'admin')]   // human carrying the admin role
```

Arguments that equal `user`/`client` constrain the identity *type*; any other
argument is treated as a required *role*.

## Notes

- Policies run **after** identity resolution, so `request->attribute('identity')`
  is populated (or null) by the time a policy runs.
- A denied `authenticated` check returns **403**, not 401. If you want "please
  log in" semantics (401) for an anonymous caller, throw
  `HttpException(401)` from the handler/guard instead — see how
  `LoginWebservice::whoami()` does it.
- Enforcement lives in the Kernel's `enforcePolicies()`; policy *logic* lives in
  your policy classes. Keep policies free of side effects — they answer a
  yes/no question.
