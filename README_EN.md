# kpapy

**English** · [Català](README_CA.md)

Automatic REST API generator for **CodeIgniter 4** (4.4 or later, PHP 8.1+), in the style of [kpcrudy](https://github.com/SIENSIS/kpcrudy).

> **kpapy is an independent library.** It does not need kpcrudy: it has its own permission resolver, id masker, validation and configuration, and it works in any CodeIgniter 4 project. kpcrudy is an **optional** integration: when a project uses both, they can share the permission resolver and the masked ids, so the web back office and the API behave the same way.

You describe a resource (a table or a model, its fields, relations, filters and permissions) in a controller method, and kpapy serves a secure, documented JSON API for it. All you need is a route and a method.

Features:

- **List** with pagination (by page or by **cursor**), **filters** with operators, **search**, **sorting**, **sparse fields** and **includes** of related records
- **Details**, **create** (`POST`), **partial and full update** (`PATCH`, `PUT`), **delete**, **trash**, **restore** and **purge** (CodeIgniter soft deletes)
- **1-N and N-N relations**, **translated fields** in a child table (`?lang=` or `Accept-Language`), **multiselect** fields, **file uploads** with signed, temporary URLs for private files
- **Custom operations** (`POST /books/{id}/lend`) and **bulk actions** (`POST /books/bulk`), with hooks before and after every write
- Validation with CodeIgniter rules (also inferred from the table), errors as **RFC 9457** `application/problem+json`, translated into six languages
- **Central permissions** (`PermissionResolver`; if the project also uses kpcrudy, one class can serve both), `setPermission()` per resource and the permission catalogue
- **Masked ids** in every URL and response; if the project also uses kpcrudy, they are **identical to kpcrudy's** (a record has the same id in the web and in the API)
- **Authentication with CodeIgniter Shield access tokens**, token **scopes** intersected with the user's permissions, HMAC tokens for server-to-server integrations
- **Login for apps with two-factor authentication**: the user's active method (authenticator app, email code) or, for users without 2FA, a **code shown in their web profile**
- **ETags**, conditional `GET` (`304`) and optimistic concurrency with `If-Match` (`412`)
- **CORS**, **rate limits** per token, per IP and for the login, HTTPS required in production
- **OpenAPI 3.1** generated from the resources, and a documentation page
- `spark` commands: generate a resource from a table, the OpenAPI document, list the API routes, create tokens, publish the configuration, install the demo

---

## Documentation

- [docs/functions_EN.md](docs/functions_EN.md): reference of every public function, option and query parameter.
- [docs/howto_EN.md](docs/howto_EN.md): recipes (public API, app login with 2FA, personal tokens, sharing permissions with kpcrudy, uploads, CORS, testing…).
- [docs/commands_EN.md](docs/commands_EN.md): the `spark` commands, with real outputs.
- [demo/README_EN.md](demo/README_EN.md): the demo library API with a web console.
- [docs/requirements.md](docs/requirements.md): the specification the library was built from.

## Installation

The library is published on [Packagist](https://packagist.org/packages/siensis/kpapy). Install it with Composer:

```bash
composer require siensis/kpapy:dev-main
```

There is no tagged release yet, so the constraint is `dev-main` (the latest commit of the `main` branch). When versions are tagged, use `composer require siensis/kpapy` (Composer will write `^1.0` or the current version) and update with `composer update siensis/kpapy`.

Requirements: PHP 8.1+, CodeIgniter 4.4+. Optional:

- [`codeigniter4/shield`](https://shield.codeigniter.com/): token authentication, scopes and the app login. Without Shield you can still use public resources or write your own `Authenticator`.
- [`siensis/kpcrudy`](https://github.com/SIENSIS/kpcrudy): only if you also want back-office CRUDs that share permissions and masked ids with the API. kpapy does not need it and Composer does not install it.

CodeIgniter discovers the `Kpapy` namespace (filter `kpapy`, commands, languages, views, migrations) on its own. If you use the app login, create its table:

```bash
php spark migrate -n Kpapy
```

## Quick start

1. A controller method that describes the resource:

```php
<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Resource;

class Books extends BaseController
{
    public function index(): ResponseInterface
    {
        return (new Resource())
            ->setTable('books')
            ->setResource('library-books')                          // name for the permissions
            ->setRelation('author_id', 'authors', ['name'], ['as' => 'author'])
            ->filters(['author_id', 'year'])
            ->searchFields(['title'])
            ->render();
    }
}
```

2. The routes (one call registers every method and path of the resource):

```php
// app/Config/Routes.php
\Kpapy\Resource::routes($routes, 'api/v1/books', 'Api\Books::index', ['filter' => 'kpapy']);
```

3. API routes use tokens, not cookies: exclude them from CodeIgniter's CSRF filter.

```php
// app/Config/Filters.php
public array $globals = [
    'before' => [
        'csrf' => ['except' => ['api/*']],
    ],
];
```

You can generate steps 1 and 2 with `php spark kpapy:make books`.

### What you get

| Request | Result |
|---|---|
| `GET /api/v1/books?filter[year][gte]=1970&sort=-year&include=author&page=2` | List |
| `GET /api/v1/books/{id}` | Details (with `ETag`) |
| `POST /api/v1/books` | Create → `201` + `Location` |
| `PATCH /api/v1/books/{id}` | Partial update (`If-Match` optional) |
| `DELETE /api/v1/books/{id}` | To the trash (or deleted), `204` |
| `POST /api/v1/books/{id}/restore` | Restore from the trash |
| `POST /api/v1/books/bulk` | Bulk action |

Every request needs `Authorization: Bearer <token>` (filter `kpapy`); use `'kpapy:public'` for resources without authentication.

### Response format

```json
{
  "data": [ { "id": "Xk3f9QaLm2", "title": "Invisible Cities", "author_id": "aB7…", "year": 1972 } ],
  "meta": { "per_page": 25, "page": 1, "total": 120, "pages": 5 },
  "links": { "self": "…?page=1", "first": "…?page=1", "prev": null, "next": "…?page=2", "last": "…?page=5" }
}
```

A single record is `{"data": {…}, "links": {"self": "…"}}`. Dates are ISO 8601 with time zone, decimals are strings, booleans are `true`/`false`.

### Errors

```json
{
  "type": "about:blank",
  "title": "Invalid data",
  "status": 422,
  "code": "validation",
  "errors": { "title": ["The title field is required."] }
}
```

`application/problem+json` (RFC 9457) with a stable `code`: `bad-request` (400), `unauthorized` (401), `forbidden` (403), `not-found` (404, also for records outside `where()`), `method-not-allowed` (405), `conflict`/`operation-failed` (409), `precondition-failed` (412), `payload-too-large` (413), `unsupported-media-type` (415), `validation` (422), `precondition-required` (428), `too-many-requests` (429, with `Retry-After`), `server-error` (500, without details outside `development`).

## Configuration

`php spark kpapy:publish config` writes `app/Config/Kpapy.php` with every option and its comment; keep only what you change. The most used:

```php
namespace Config;

class Kpapy extends \Kpapy\Config\Kpapy
{
    public int $perPage                = 25;
    public int $maxPerPage             = 100;
    public bool $maskIds               = true;
    public ?string $permissionResolver = \App\Libraries\Permissions::class;
    public bool $denyByDefault         = true;
    public array $corsOrigins          = ['https://app.example.com'];
    public int $rateLimit              = 120;      // per token and minute
    public string $profileUrl          = 'https://example.com/profile';
}
```

Every option is in [docs/functions_EN.md](docs/functions_EN.md#configuration).

## Demo

The library includes a small library API (books, authors, tags, loans) with demo users, the app login with both two-factor methods, a web console to send requests and the documentation page. It is installed in any project with Shield:

```bash
php spark kpapy:demo install
```

and removed without a trace with `php spark kpapy:demo uninstall`. See [demo/README_EN.md](demo/README_EN.md).

## Languages

Error titles, validation and authentication messages are in **Catalan, Spanish, English, French, Portuguese and German** (`src/Language/{code}/Kpapy.php`), following the request locale; missing locales fall back to English. `php spark kpapy:publish lang ca` copies a file to `app/Language/` to adapt it.

## Security notes

- API routes never use cookies or sessions: authentication is a Shield access token (or an HMAC token) in the `Authorization` header, so CSRF does not apply. Exclude them from the `csrf` filter, and never mix the API with session-authenticated routes.
- Permissions are checked on the server for every operation, record and custom operation; the token's scopes can only **restrict** what the user can do.
- `where()` limits every operation: a record outside it is `404`, never revealed. Equality conditions are also **enforced on write**.
- Only the fields allowed for writing are read from the request body (mass-assignment protection); with `strictInput` the rest is rejected.
- Sensitive fields (`password`, `token`, `secret`…) are never returned unless listed explicitly; passwords are stored hashed.
- Masked ids are encrypted and signed; a forged or altered code is `404`.
- Private files are only served through signed URLs that expire.
- The app login is throttled per IP and per account, uses generic messages, single-use challenges with a limited number of attempts, and always a second factor.
- Personal tokens are only created from the web profile (`TokenService`), never through the API: a leaked token cannot create others.
- In production, requests that are not HTTPS are refused (`requireHttps`), and errors never include internal details.
