# kpapy — Requirements

> Specification of **kpapy**, an automatic REST API generator for CodeIgniter 4, in the style of
> [kpcrudy](https://github.com/SIENSIS/kpcrudy). This document is the starting point for the
> development: it describes what the library must do, not how every line is written. Once it is
> validated, the library is developed in this repository (`main`).

---

## 1. Goal

kpcrudy builds a complete back-office screen (list, form, details…) from a table or a model with
a fluent API. **kpapy does the same for a JSON API**: you describe a resource (table or model,
fields, relations, filters, permissions) and the library serves a secure, documented REST API
for it, with authentication by tokens.

* Works in **any CodeIgniter 4 project** (it does not depend on any application skeleton).
* Shares concepts and names with kpcrudy, so a project that uses both describes permissions,
  masked ids, scopes and hidden fields the same way in the web and in the API. kpcrudy is
  **not** a dependency.
* Main use cases: mobile apps and SPAs, integrations with other systems, and the API core of an
  application skeleton (news, events, notifications…).

## 2. Baseline

* **PHP 8.1+**, **CodeIgniter 4.4+** (same range as kpcrudy), `declare(strict_types=1)`,
  PSR-12, PHPStan level 6.
* Package `siensis/kpapy`, namespace `Kpapy\`, licence MIT.
* **Optional integrations** (`suggest` in `composer.json`, detected at runtime):
  * `codeigniter4/shield`: authentication with access tokens (the default authenticator when it
    is installed) and two-factor login;
  * `siensis/kpcrudy`: compatible masked ids and a shared permission resolver.
* Databases: anything CodeIgniter's Query Builder supports; tested on MySQL/MariaDB and SQLite.
* Code comments in Catalan (as in kpcrudy); CLI messages in English; error messages translated.

## 3. Resources (fluent API)

A **resource** is one endpoint family (`/api/v1/news`, `/api/v1/news/{id}`). It is configured
in a controller method, like a kpcrudy CRUD:

```php
use Kpapy\Resource;

public function news()
{
    return (new Resource())
        ->setModel(NewsModel::class)
        ->setResource('news-posts')                      // name for the permission resolver
        ->fields(['id', 'title', 'summary', 'body', 'category_id', 'published_at'])
        ->writeFields(['title', 'summary', 'body', 'category_id'])
        ->where('status', 'published')
        ->setRelation('category_id', 'news_categories', ['id', 'name'], ['as' => 'category'])
        ->filters(['category_id', 'published_at'])
        ->searchFields(['title', 'summary'])
        ->defaultOrder('published_at', 'desc')
        ->hideFields(['author_id'])
        ->addOperation('read', fn (string $id, array $row, Resource $api) => $this->markRead($id))
        ->render();
}
```

### 3.1 Data source

* `setTable(string)`, `setModel(Model|string)`, `setDatabase()`, `setPrimaryKey()`: as in
  kpcrudy. Model mode reuses `allowedFields`, validation rules and messages, timestamps, soft
  deletes and model callbacks.
* `where(field, value)` / `where(array)`: limits every operation (list, show, write, delete,
  bulk). Simple equality conditions are **enforced on write** (the field is set to that value
  and cannot be changed by the client), like kpcrudy.
* `softDeletes(bool, field)`.

### 3.2 Fields

* `fields(array)`: fields returned (default: every column except sensitive ones and
  `deleted_at`).
* `listFields(array)`: fields of the list when they differ from the details.
* `writeFields(array)`, `addFields(array)`, `editFields(array)`: fields the client may send
  (default: fields of the model's `allowedFields`, or every non-key, non-timestamp column).
  **Anything else in the request body is ignored** (mass-assignment protection) or rejected with
  `422` when `strictInput` is enabled.
* `hideFields(array)`: removes fields from everything (output, filters, search, sorting,
  input), like kpcrudy's `hideFields()`; it takes priority over the other lists.
* `readonlyFields(array)`, `requiredFields(array)`, `setDefault(field, value)`,
  `setRules(field, rules)` (CodeIgniter rules, `{id}` placeholder), rules inferred from the
  table structure (`inferRules`, as in kpcrudy).
* `sensitiveFields` (config): passwords, tokens, secrets… never returned unless listed
  explicitly in `fields()`; passwords are written hashed (`fieldType('password', 'password')`).
* `fieldType(field, type, options)`: output casting and input conversion: `int`, `float`,
  `decimal` (string in JSON), `bool`, `date`, `datetime` (ISO 8601 with time zone), `time`,
  `json`, `multiselect` (JSON array; stored as JSON or CSV like kpcrudy), `enum`/`select`
  (validated against the options), `password`, `upload` / `image` (see 3.7), `virtual`.
* `callbackField(field, fn ($value, array $row): mixed)`: computed or transformed output;
  `transform(fn (array $item, array $row): array)` for the whole item.
* `setTranslations(table, fk, localeField, fields, locales, options)`: translated fields stored
  in a child table, as in kpcrudy. Output in the requested language (`?lang=ca` or
  `Accept-Language`, with the configured fallback chain) or every language with
  `?lang=*` (`{"title": {"ca": "…", "es": "…"}}`); input accepts either a value for the request
  language or an object per language.

### 3.3 Relations

* `setRelation(field, table, display, options)`: 1-N. Output as the foreign key, and with
  `?include=category` as a nested object with the selected columns. Input accepts the id
  (masked if masking is on) and validates that it exists and is allowed (`where` option).
* `setRelationNN(name, junction, otherTable, thisKey, otherKey, display, options)`: N-N.
  Output as an array of ids, or nested objects with `?include=`; input as an array of ids,
  saved in a transaction.
* `?include=` only accepts the relations declared on the resource (no arbitrary joins) and can
  be limited by permission.

### 3.4 Listing: pagination, filters, search, sorting, sparse fields

* **Pagination:** `?page=2&per_page=50` (offset), with `perPage` / `maxPerPage` in the config;
  optional **cursor pagination** (`->cursorPagination('published_at')`, `?cursor=…`) for large
  tables and infinite scroll.
* **Filters** (only on fields listed in `filters()`): `?filter[status]=published`,
  `?filter[price][gte]=10`, operators `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in` (comma-separated),
  `like` (wildcards escaped), `between`, `null` / `notnull`; values converted and validated with
  the field type (invalid values → `400`).
* **Search:** `?q=text` over `searchFields()`.
* **Sorting:** `?sort=-published_at,title` over sortable fields (`unsortable()` to exclude).
* **Sparse fields:** `?fields=id,title` (only among the allowed ones).
* **Trash:** `?trashed=with|only` when the resource has soft deletes and the user has the
  `trash` permission.
* `defaultOrder()`, and a total count that can be disabled (`->withoutTotal()`) for very large
  tables.

### 3.5 Operations and HTTP mapping

| Operation | Method and path | Success | Permission |
|---|---|---|---|
| list | `GET /r` | `200` | `list` |
| show | `GET /r/{id}` | `200` | `show` |
| add | `POST /r` | `201` + `Location` | `add` |
| edit | `PATCH /r/{id}` (partial), `PUT /r/{id}` (optional, full) | `200` | `edit` |
| delete | `DELETE /r/{id}` (to the trash if soft deletes) | `204` | `delete` |
| restore | `POST /r/{id}/restore` | `200` | `restore` |
| purge | `DELETE /r/{id}?purge=1` | `204` | `purge` |
| custom operation | `POST /r/{id}/{key}` or `POST /r/{key}` (or `GET` if declared) | `200`/`201`/`202`/`204` | its `permission` (default: the key) |
| bulk | `POST /r/bulk` `{"action": "...", "ids": [...]}` | `200` with a per-id result | `bulk` + the action's permission, per record |

* `unsetAdd()`, `unsetEdit()`, `unsetDelete()`, `readOnly()`… as in kpcrudy; disabled
  operations answer `405` (with `Allow`).
* `addOperation(key, callback, options)`: same signature and options as kpcrudy
  (`row`, `method`, `permission`, `visible`, `trash`); the callback returns data (serialised as
  `data`), a `ResponseInterface`, `false` (→ `409`/`422` with a message) or `null` (`204`).
* `addBulkAction(key, callback, options)`: ids filtered by `where()` and by the per-record
  permission before calling the callback.
* **Hooks:** `beforeInsert`, `afterInsert`, `beforeUpdate`, `afterUpdate`, `beforeDelete`,
  `afterDelete`, `afterRestore`, `afterWrite` — same signatures as kpcrudy; `before*` may
  return modified data, an error message (→ `422`) or `false`.
* **Optimistic concurrency:** every item has an `ETag` (hash of the row or of `updated_at`);
  `PATCH`/`PUT`/`DELETE` with `If-Match` answer `412` when the record changed. A setting can
  make `If-Match` mandatory for writes.
* **Conditional GET:** `ETag` / `Last-Modified` on lists and items, `304 Not Modified`.

### 3.6 Responses and errors

Simple envelope (JSON, UTF-8, `application/json`):

```json
{
  "data": [ { "id": "Xk3f9QaLm2", "title": "…", "category": { "id": "aB7…", "name": "…" } } ],
  "meta": { "page": 1, "per_page": 25, "total": 120, "pages": 5 },
  "links": { "self": "…?page=1", "first": "…", "prev": null, "next": "…?page=2", "last": "…?page=5" }
}
```

* Single item: `{"data": {…}, "links": {"self": "…"}}`. Custom operations may add `meta`.
* Envelope keys configurable; an option to return the bare item for clients that need it.
* **Errors** with RFC 9457 (`application/problem+json`): `type`, `title`, `status`, `detail`,
  `instance`, and for validation `errors: {"field": ["message"]}`. Titles and details are
  translated (ca, es, en, fr, pt, de, like kpcrudy). No stack traces or SQL errors outside
  `development`.
* Status codes: `400` (malformed request, invalid filter), `401` (not authenticated),
  `403` (not allowed), `404` (not found or outside `where()` — never revealing which), `405`,
  `409`, `412`, `415` (unsupported content type), `422` (validation), `429` (rate limit, with
  `Retry-After`), `500`.
* Dates in ISO 8601 with time zone; decimals as strings; booleans as `true`/`false`.

### 3.7 Files

* Upload with `multipart/form-data` on `POST`/`PATCH`, or as a separate step
  (`POST /r/{id}/files/{field}`), with the same validation as kpcrudy's upload fields
  (extensions, MIME, size, random names, `subdir`).
* Output: the public URL, or a short-lived **signed URL** for private files (served through a
  route that checks the signature and the permission).

### 3.8 Masked ids

* `maskIds(bool, context)` and the config `maskIds`, `maskKey`, `idMasker`, as in kpcrudy.
* **Compatibility:** with the same key and context (the table name by default), codes are
  **identical to kpcrudy's** `DefaultIdMasker`, so the same record has the same id in the web
  and in the API. When kpcrudy is installed, its masker classes may be used directly.
* Ids of relations (input and `?include=` output) are masked with the related table's context.

## 4. Permissions

* `setResource(string)` (default: the table) and a **permission resolver** with the same
  semantics as kpcrudy: `Kpapy\PermissionResolver::allows(string $resource, string $operation,
  ?array $row, Resource $api): ?bool` — `true` allows, `false` denies (last word), `null` no
  opinion; then `setPermission()` rules; then `denyByDefault`.
* One class can implement both `Kpcrudy\PermissionResolver` and `Kpapy\PermissionResolver`, so a
  project has a single source of truth; a bridge `Kpapy\Bridge\KpcrudyResolver` adapts an
  existing kpcrudy resolver.
* `setPermission(operation, bool|Closure)`, `isAllowed()`, `permissionCatalogue()`,
  `IMPLIED_PERMISSIONS` / `impliedPermissions()` — same names and meaning as kpcrudy.
* `search`, `filters` and `bulk` are checked like in kpcrudy (and can be mapped to `list` by the
  resolver).
* **Own records:** `where('user_id', $id)` (only own records) or a per-record `setPermission()`
  closure (sees all, changes own), as documented for kpcrudy.
* The **token's scopes** are applied on top of the user's permissions (see 5.2).

## 5. Authentication

### 5.1 Authenticators

* An `Authenticator` interface (`authenticate(IncomingRequest): ?ApiUser`), configurable per
  project and per route group.
* **Shield access tokens** (default when Shield is installed): `Authorization: Bearer <token>`,
  using Shield's `tokens` authenticator; the user is available through `auth()->user()` and
  `$api->user()`.
* Optional: Shield **HMAC** tokens (signed requests for server-to-server integrations).
* A `Public` mode for resources that need no authentication (e.g. public news), still subject to
  rate limits and `where()`.
* API requests never use cookies or sessions, so CSRF does not apply to them; the API route
  group must be excluded from the `csrf` filter and must reject cookie-only authentication.

### 5.2 Tokens and scopes

* Tokens have a name, **scopes**, expiry, last-used date and last-used IP. Scopes are permission
  names or Shield-style wildcards (`news-posts.*`); a token's effective permissions are the
  **intersection** of the user's permissions and its scopes (`*` = everything the user can do).
* Optional `TokenController` endpoints (enabled with a route helper):
  * `GET /me/tokens`, `DELETE /me/tokens/{id}`: the user's tokens (never showing the secret
    again);
  * creating personal tokens is meant for the web profile of the application (the library
    provides a service and a view partial, not an API endpoint, so a leaked token cannot mint
    new tokens).

### 5.3 Login for apps, with two-factor authentication

`Kpapy\Auth\LoginController` (optional, enabled with a route helper) issues tokens to apps:

1. `POST /auth/token` with `{"email", "password", "device_name", "scopes"?}`.
   * Credentials are checked with Shield (throttling per IP and per account, generic messages,
     banned or inactive users refused).
   * **The user's active 2FA method is used:**
     * if the user has a 2FA method enabled (email code, TOTP app…), the response is
       `202 Accepted` with `{"challenge": "<id>", "method": "email|totp", "expires_in": 300}`
       (for email, the code is sent now);
     * if the user has **no 2FA enabled**, the **web code** method is used: the response is
       also `202` with `{"challenge": "<id>", "method": "web", "profile_url": "...",
       "expires_in": 300}`, and the app tells the user to open their profile in the web
       application (logged in as usual). The profile shows the pending sign-in ("New sign-in
       from <device name>, <time>") with a **6-digit validation code**, and buttons to see the
       code or deny the sign-in. The user types that code in the app to finish. Nothing is
       sent by email or SMS: having an active web session is the second factor.
2. `POST /auth/token/verify` with `{"challenge", "code"}` (or a recovery code) → `201` with
   `{"token": "...", "expires_at": "...", "scopes": [...]}`. Challenges are single-use, expire,
   and lock after a few failed attempts.
3. `DELETE /auth/token` revokes the current token (logout); `POST /auth/token/refresh`
   (optional) rotates it before it expires.

* 2FA methods are pluggable through a `TwoFactorProvider` interface (`methodFor(User)`,
  `start(User)`, `verify(User, code)`); kpapy ships:
  * an **email** provider (a 6-digit code sent to the user's email);
  * a **TOTP** provider interface the application implements (or an optional TOTP provider when
    `spomky-labs/otphp` is installed);
  * the **web code** provider, used when the user has no other method: pending sign-ins are
    stored (`kpapy_login_challenges`: user, device, IP, code hash, status, expiry) and kpapy
    provides a service (`pendingFor(User)`, `deny()`) and a view partial for the application's
    profile page that lists them with their codes (the code is generated on the server, shown
    only to the logged-in owner and stored hashed after it is displayed).
  A project decides which methods are allowed and their order (config `twoFactorMethods`).
* Every login, failed attempt, token creation and revocation is reported through an event
  (`kpapy.auth.*`) so the application can log it.

## 6. Routing

* `Resource::routes($routes, 'api/v1/news', 'Api\News::news', ['filter' => 'kpapy-auth', 'as' => 'api.news'])`:
  registers every method and path of the resource (list, item, operations, bulk, files) with
  one handler, like kpcrudy's `Crud::routes()`; only the base route gets the name.
* `Kpapy\Api::group($routes, 'api/v1', $options, fn ($routes) => …)`: a group with the API
  filters (authentication, CORS, rate limit, JSON errors, no CSRF).
* A route check in development warns about routes that collide with a resource (as kpcrudy's
  `checkRoutes()`).

## 7. Cross-cutting concerns

* **CORS:** allow-list of origins, methods and headers in the config; preflight handled by the
  API filter; `Vary: Origin`.
* **Rate limiting:** per token (and per IP for public and login routes) with CodeIgniter's
  Throttler; limits per route group and per resource; `429` + `Retry-After` and
  `RateLimit-*` headers.
* **Input:** only `application/json` (and `multipart/form-data` for uploads); body size limit;
  invalid JSON → `400`.
* **Security headers** on every response (`X-Content-Type-Options: nosniff`,
  `Cache-Control: no-store` for private data), and `HTTPS` required outside development.
* **Logging/audit:** events `kpapy.request`, `kpapy.write` (with operation, resource, id, user,
  token) for the application's activity log.
* **Versioning:** the prefix carries the version (`api/v1`); resources can be registered under
  several versions; a `Deprecation` / `Sunset` header can be set per route.

## 8. OpenAPI documentation

* Every resource describes itself: fields and types (from the table metadata, `fieldType()` and
  the rules: `maxLength`, `enum`, `format`, `nullable`), relations and `include`, filters,
  sorting, operations, custom operations, error responses and security (bearer token, scopes).
* `php spark kpapy:openapi [--output=public/api/openapi.json] [--format=json|yaml]` builds an
  **OpenAPI 3.1** document from the registered routes; a route can serve it dynamically.
* An optional documentation page (built in: server-side HTML with a "Try it" form per endpoint, see `docsTryIt`; originally planned as Swagger UI or Redoc, vendored locally, CSP-compatible,
  disabled in production unless enabled) to try the API with a token.
* Descriptions and examples can be added per resource (`->describe()`, `->example()`).

## 9. Configuration (`Config\Kpapy`)

Like kpcrudy, a `Kpapy\Config\Kpapy` class that the project can extend
(`php spark kpapy:publish config`): prefix, `perPage`, `maxPerPage`, `strictInput`, envelope
keys, date format and time zone, `maskIds` / `maskKey` / `idMasker`, `permissionResolver`,
`denyByDefault`, `sensitiveFields`, authenticator, token defaults (expiry, default scopes),
2FA policy (`requireTwoFactor`), CORS, rate limits, upload paths and limits, OpenAPI info
(title, version, servers), `requireIfMatch`, debug.

## 10. Spark commands

* `kpapy:make [table] [--namespace] [--path] [--route] [--as] [--model] [--dry-run] [--force]`:
  generates the controller method and route of a resource from a table (like `kpcrudy:make`,
  including generation into modules).
* `kpapy:openapi`, `kpapy:routes` (list the API routes and their permissions),
  `kpapy:token <user> [--scopes] [--expires]` (create a token from the console, e.g. for an
  integration), `kpapy:publish <config|lang>`, `kpapy:demo <install|status|reset|uninstall>`.

## 11. Integrated demo

Like kpcrudy's demo, installed in any project with `php spark kpapy:demo install` and removed
without leaving a trace:

* a few tables (e.g. authors, books, tags, loans) covering relations, translations, a
  multiselect, soft deletes, uploads, custom operations and own-records rules;
* demo users with different permissions, and tokens shown on the demo page;
* a small, CSP-safe **web console** page that lists the resources, lets you pick a demo user's
  token and run requests (method, path, body) showing the response — plus the OpenAPI document
  and the documentation page;
* the login flow with 2FA (email codes written to the log in the demo).

## 12. Quality

* PHPUnit tests (SQLite) for every operation, filter operator, include, pagination, error
  status, permission rule, scope intersection, masked id compatibility with kpcrudy, ETag /
  `If-Match`, rate limiting, CORS preflight, uploads and the login flow (with and without 2FA).
* A `Kpapy\Test\ApiTestTrait` for projects (acting as a user with given scopes, JSON
  assertions).
* GitHub Actions: PHP 8.1–8.4 × CodeIgniter 4.4 and latest, PHPStan, php-cs-fixer.

## 13. Documentation (Catalan and English, as in kpcrudy)

`README_CA.md` / `README_EN.md`, `docs/functions_*.md` (reference), `docs/howto_*.md`
(recipes: mobile app login with 2FA, a public read-only API, own records, sharing permissions
with kpcrudy, uploads, versioning, CORS for a SPA), `docs/security_*.md`, `docs/commands_*.md`,
`demo/README_*.md`, and `CHANGELOG.md`.

## 14. Out of scope (for now)

GraphQL, JSON:API format, OAuth2 authorization server, webhooks, server-sent events, offline
sync. The design should not prevent adding webhooks or an OAuth2 authenticator later.
