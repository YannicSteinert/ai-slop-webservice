# Routing

Routing happens in two stages, both in [`Router`](../src/Routing/Router.php):

1. **Module resolution** — the first path segment selects a module (webservice
   class) from the module map.
2. **Attribute matching** — the remaining path is matched against that class's
   `#[Route]` methods.

So `GET /users/5/show` → module `users` → `UsersWebservice` → match `/5/show`
against its routes → `show(int $user_id)` with `$user_id = 5`.

## Declaring routes

Put the [`#[Route]`](../src/Attributes/Route.php) attribute on a public method:

```php
#[Route('GET', '/{user_id}/show')]
public function show(int $user_id): Response { ... }
```

- First argument: the HTTP method (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, …).
- Second argument: the **module-relative** path (always starts at `/`).
- The attribute is **repeatable** — one method can serve several routes:

```php
#[Route('GET', '/{provider}/callback')]
#[Route('POST', '/{provider}/callback')]
public function callback(string $provider): Response { ... }
```

Routes are read once per class via reflection and cached.

## Path parameters

Use `{name}` for a path segment that becomes a handler argument:

```php
#[Route('GET', '/{id}')]
public function show(int $id): Response { ... }
```

### Constraints

Add a regex after a colon to constrain a parameter:

```php
#[Route('GET', '/{id:\d+}')]        // only matches digits
#[Route('GET', '/{slug:[a-z-]+}')]  // lowercase + hyphens
```

Without a constraint a parameter matches any single segment (`[^/]+`).

### Multiple parameters

Fully supported; they bind by name, in any order:

```php
#[Route('GET', '/{group_id}/members/{user_id}')]
public function member(int $group_id, int $user_id): Response { ... }
```

`GET /users/7/members/42` → `member(7, 42)`.

## How arguments are bound

When the Kernel invokes a handler it fills each parameter, in order, from the
first source that applies ([`Kernel::resolveArguments()`](../src/Kernel.php)):

1. **A path parameter of the same name** — cast to the declared scalar type
   (`int`, `float`, `bool`; otherwise left as string).
2. **The `Request`** — if a parameter is type-hinted `Request`.
3. **A container service** — any other class type hint is resolved from the
   [container](architecture.md#the-dependency-container).
4. **A default value** — if the parameter has one.
5. **`null`** — as a last resort.

```php
use bapersbw\Http\Request;

#[Route('POST', '/{id}/notes')]
public function addNote(int $id, Request $request): Response
{
    $text = (string) $request->input('text', '');
    // ...
}
```

## What a handler may return

`Kernel::dispatch()` normalises the return value:

| Return | Becomes |
|---|---|
| a `Response` | sent as-is |
| `null` | `204 No Content` |
| anything else (array, object, scalar) | `200` JSON of that value |

Prefer the base-class helpers for clarity: `ok()`, `created()`, `json()`,
`noContent()`, `redirect()`.

## Matching order and literal vs. parameter routes

The router returns the **first matching route in declaration order**. When a
module has both a literal route and a `{param}` route that could match the same
path, declare the literal one **first**. The Login module relies on this:

```php
#[Route('GET', '/logout')]     // declared before…
public function logout() { ... }

#[Route('GET', '/{provider}')] // …this, so /login/logout isn't read as provider="logout"
public function begin(string $provider) { ... }
```

Multi-segment routes (`/{provider}/callback`) never collide with single-segment
ones (`/{provider}`) because the parameter pattern stops at `/`.

## Errors the router raises

- **Unknown module** or **no route matches the method+path** → `HttpException(404)`.
- **Path matches but the HTTP method doesn't** → `HttpException(405)` with an
  `Allow` header listing the methods that do match.

`HEAD` is treated as `GET` for matching.

---

## The Request object

[`Request`](../src/Http/Request.php) is an immutable-ish value object. In a
handler it's `$this->request`; in tests you build one directly (or via the
`TestClient`). Built from PHP globals with `Request::fromGlobals()` in the front
controller.

Fields (readonly): `method`, `path`, `query`, `body`, `headers`, plus an
attributes bag.

| Method | Returns |
|---|---|
| `header($name, $default = null)` | a header (case-insensitive) |
| `query($key, $default = null)` | a query-string value |
| `input($key, $default = null)` | a parsed body value (form or JSON) |
| `attribute($key, $default = null)` | a request-scoped attribute |
| `withAttribute($key, $value)` | a copy with one attribute added |

The Kernel uses the attribute bag to carry request-scoped state the handler and
policies can read — notably `request_id`, the resolved `identity`, and the
`session`.

JSON bodies are parsed automatically when `Content-Type: application/json`;
otherwise `$_POST` form fields are used.

## The Response object

[`Response`](../src/Http/Response.php) holds a body, status and headers.

```php
new Response('hello', 200, ['Content-Type' => 'text/plain']);
Response::json(['ok' => true], 200);
Response::redirect('/login', 302);
$response->withHeader('X-Thing', 'value');
```

`send()` emits the status line, headers and body — the front controller calls it
once. Inside a module, prefer the `Webservice` helpers over constructing
responses by hand.
