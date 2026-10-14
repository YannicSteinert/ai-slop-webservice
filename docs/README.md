# Developer Documentation

Welcome. This is a small, module-based PHP web service framework. Every feature
area is a **module**: a self-contained API with its own routes, data access and
migrations — think one microservice per module, all served by one app.

If you only read one page, read **[Creating a Webservice](creating-a-webservice.md)** —
it walks through building a new module end to end.

## Contents

1. [Getting Started](getting-started.md) — install, run, test, project layout
2. [Architecture](architecture.md) — the request lifecycle and how the pieces fit
3. [Creating a Webservice](creating-a-webservice.md) — build a module step by step
4. [Routing](routing.md) — attribute routes, path parameters, argument binding
5. [Authentication](authentication.md) — SAML/LDAP login, sessions, OAuth2 tokens
6. [Authorization & Policies](authorization.md) — `#[Authorize]` and the `Gate`
7. [Database & Repositories](database.md) — the `Connection` and `Repository`
8. [Migrations](migrations.md) — module-owned schema changes + the console
9. [Logging](logging.md) — PSR-3 logging and where it happens
10. [Testing](testing.md) — the `TestClient` and the test runner

## The 60-second mental model

A request flows through one object, the **Kernel**:

```
Request → Kernel
   1. start the session
   2. resolve the identity   (OAuth2 token, else session)
   3. route                  (module → attribute route)
   4. enforce policies        (#[Authorize] via the Gate)
   5. invoke the handler      (bind args from the path + container)
   6. return a Response       (with logging around the whole thing)
```

The URL is read as `/{module}/{...rest}`. The first segment picks a **module**
(a `Webservice` subclass) from [`config/modules.php`](../config/modules.php);
the rest is matched against that class's `#[Route]` methods.

Everything is wired once in [`Application`](../src/Application.php) (the
composition root) and handed to the `Kernel`. There is no hidden magic — when in
doubt, open `src/Kernel.php` and read the `handle()`/`process()` methods top to
bottom.

## Conventions used in these docs

- Namespaces are rooted at `bapersbw\` → `src/` (PSR-4).
- File references like [`src/Kernel.php`](../src/Kernel.php) are clickable.
- Code snippets are complete enough to copy; trim the comments as you like.
