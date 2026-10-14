# Creating a Webservice (a Module)

This is the core workflow. We'll build a complete **`products`** module: routes,
a repository, a migration, and a test. Every module follows this same shape.

A module is a directory under `src/Modules/<Name>/` containing:

```
src/Modules/Products/
    ProductsWebservice.php          the API (routes)
    ProductsRepository.php          data access (optional)
    Migrations/                     this module's schema (optional)
        M0001_CreateProductsTable.php
```

## Step 1 — Write the webservice class

Create `src/Modules/Products/ProductsWebservice.php`. Extend
[`bapersbw\Webservice`](../src/Webservice.php) and declare routes with the
`#[Route]` attribute. Paths are **relative to the module** — the `/products`
prefix is implied by the module map (step 3).

```php
<?php

declare(strict_types=1);

namespace bapersbw\Modules\Products;

use bapersbw\Attributes\Route;
use bapersbw\Http\Response;
use bapersbw\Webservice;

final class ProductsWebservice extends Webservice
{
    #[Route('GET', '/')]                       // GET /products
    public function index(): Response
    {
        return $this->ok(['products' => []]);
    }

    #[Route('GET', '/{id}')]                    // GET /products/42
    public function show(int $id): Response
    {
        return $this->ok(['id' => $id]);
    }

    #[Route('POST', '/')]                       // POST /products
    public function store(): Response
    {
        $name = (string) $this->request->input('name', '');

        return $this->created(['id' => 1, 'name' => $name]);
    }
}
```

Things to notice:

- **`$this->request`** is the current [`Request`](routing.md#the-request-object) —
  available because the Kernel calls `withContext()` before invoking the handler.
- **Response helpers** come from the base class: `ok($data)` (200),
  `created($data)` (201), `json($data, $status)`, `noContent()` (204),
  `redirect($location)`. Returning a plain array/value also becomes JSON; see
  [Routing → return values](routing.md#what-a-handler-may-return).
- **`{id}` becomes the `$id` argument**, cast to the declared type (`int` here).
  See [Routing](routing.md) for parameters, constraints and multiple params.

## Step 2 — (optional) Add a repository for data access

If the module talks to the database, give it a repository extending
[`Repository`](../src/Database/Repository.php). Create
`src/Modules/Products/ProductsRepository.php`:

```php
<?php

declare(strict_types=1);

namespace bapersbw\Modules\Products;

use bapersbw\Database\Repository;

final class ProductsRepository extends Repository
{
    protected function table(): string
    {
        return 'products';
    }
}
```

You now have `find()`, `all()`, `where()`, `create()`, `update()`, `delete()`
for free — see [Database & Repositories](database.md).

### How dependencies reach your webservice

Declare them in the constructor; they are **autowired**. The repository only
needs a `Connection`, which the container knows how to build, so this just
works:

```php
final class ProductsWebservice extends Webservice
{
    public function __construct(
        private readonly ProductsRepository $products,
    ) {
    }

    #[Route('GET', '/')]
    public function index(): Response
    {
        return $this->ok(['products' => $this->products->all()]);
    }

    #[Route('GET', '/{id}')]
    public function show(int $id): Response
    {
        $product = $this->products->find($id);
        if ($product === null) {
            throw new \bapersbw\Exceptions\HttpException(404, 'Product not found.');
        }

        return $this->ok($product);
    }
}
```

The Kernel resolves the webservice via the container, which inspects the
constructor, sees `ProductsRepository`, builds it, sees *it* needs a
`Connection`, and supplies the one bound in `Application`. No wiring needed. You
can inject any autowireable service this way (`AuthManager`, `SessionGuard`,
`Psr\Log\LoggerInterface`, another repository, …). See
[Architecture → container](architecture.md#the-dependency-container).

## Step 3 — Register the module

Add one line to [`config/modules.php`](../config/modules.php):

```php
use bapersbw\Modules\Products\ProductsWebservice;

return [
    'users'    => UsersWebservice::class,
    'login'    => LoginWebservice::class,
    'products' => ProductsWebservice::class,   // ← GET/POST /products/...
];
```

The key is the first URL segment. That's the entire registration — the router
discovers the routes from the class's attributes.

## Step 4 — (optional) Add a migration

Put schema changes in the module's `Migrations/` folder; they are discovered and
run by the console. Create
`src/Modules/Products/Migrations/M0001_CreateProductsTable.php`:

```php
<?php

declare(strict_types=1);

namespace bapersbw\Modules\Products\Migrations;

use bapersbw\Database\Connection;
use bapersbw\Database\Migration;

final class M0001_CreateProductsTable implements Migration
{
    public function up(Connection $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE products (
                id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(200) NOT NULL,
                created_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME()
            )
            SQL);
    }

    public function down(Connection $db): void
    {
        $db->execute("IF OBJECT_ID('products', 'U') IS NOT NULL DROP TABLE products");
    }
}
```

Then:

```bash
php bin/console migrate
```

Name classes with a sortable prefix (`M0001_`, `M0002_`, …) — they run in that
order. See [Migrations](migrations.md).

## Step 5 — Test it

Use the [`TestClient`](../src/Testing/TestClient.php):

```php
// tests/products_test.php
<?php
test('GET /products returns a list', function (): void {
    client()->get('/products')->assertOk()->assertJsonContains(['products' => []]);
});

test('GET /products/{id} binds the id', function (): void {
    client()->get('/products/42')->assertOk()->assertJsonContains(['id' => 42]);
});
```

Run `php tests/run.php`. See [Testing](testing.md) for authenticated requests,
asserting on logs, and DB-backed tests.

## Checklist for a new module

- [ ] `src/Modules/<Name>/<Name>Webservice.php` extends `Webservice`, methods carry `#[Route]`
- [ ] (optional) `<Name>Repository.php` extends `Repository`
- [ ] (optional) `Migrations/M####_*.php` implement `Migration`
- [ ] one line in `config/modules.php`
- [ ] a `tests/<name>_test.php`

No other file needs to change. That isolation is the point — a module is a
self-contained slice of the system.

## Securing routes (preview)

To require authentication or a role, add `#[Authorize]` and register a policy:

```php
#[Authorize('authenticated', 'user', 'admin')]   // human with the admin role
#[Route('POST', '/')]
public function store(): Response { /* ... */ }
```

Full details in [Authorization & Policies](authorization.md).
