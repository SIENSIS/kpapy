# Function reference

**English** · [Català](functions_CA.md)

Reference of the public classes and functions of kpapy. Every example assumes:

```php
use Kpapy\Resource;
```

## Index

- [Creating and running](#creating-and-running): `__construct`, `render`, `routes`, `setBasePath`
- [Data source](#data-source): `setTable`, `setModel`, `setDatabase`, `setPrimaryKey`, `where`, `softDeletes`
- [Fields](#fields): `fields`, `listFields`, `writeFields`, `addFields`, `editFields`, `hideFields`, `readonlyFields`, `requiredFields`, `setDefault`, `setRules`, `fieldType`, `callbackField`, `transform`
- [Relations](#relations): `setRelation`, `setRelationNN`
- [Translations](#translations): `setTranslations`
- [Listing](#listing): `filters`, `searchFields`, `unsortable`, `defaultOrder`, `setPerPage`, `cursorPagination`, `withoutTotal`, query parameters
- [Operations and HTTP](#operations-and-http): the mapping table, input, output, ETags
- [Custom operations and bulk actions](#custom-operations-and-bulk-actions): `addOperation`, `addBulkAction`, `fail`
- [Hooks](#hooks): `beforeInsert`, `afterInsert`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`, `afterRestore`, `afterWrite`
- [Permissions](#permissions): `setPermission`, `unset*`, `readOnly`, `setResource`, `setPermissionResolver`, `isAllowed`, `permissionCatalogue`, `impliedPermissions`, `PermissionResolver`, scopes, own records, `KpcrudyResolver`
- [Masked ids](#masked-ids): `maskIds`, `encodeId`, `decodeId`
- [Files](#files): upload fields, signed URLs
- [Access from callbacks](#access-from-callbacks): `getTable`, `getPrimaryKey`, `getConfig`, `getRequest`, `user`, `url`, `masksIds`
- [Errors](#errors): `ApiException`
- [The `kpapy` filter](#the-kpapy-filter)
- [Authentication](#authentication): `Authenticator`, `Context`, `TokenService`, `AuthRoutes`, app login, two-factor providers, events
- [OpenAPI and documentation](#openapi-and-documentation): `describe`, `DocsController`, `Generator`
- [Configuration](#configuration): `Config\Kpapy`

---

## Creating and running

### `__construct(?\Kpapy\Config\Kpapy $config = null)`

Creates a resource. Without a parameter it uses `config('Kpapy')` (your `app/Config/Kpapy.php` if it exists, or the library's). A specific configuration can be passed, e.g. for a module with its own settings.

### `render(): ResponseInterface`

Handles the request: resolves the method and the path, checks the permissions, runs the operation and returns the JSON response. Every `Kpapy\Http\ApiException` becomes a `problem+json` response; any other exception becomes a `500` (with the class and message only in `development`) and is logged.

```php
public function index(): ResponseInterface
{
    return (new Resource())->setTable('books')->render();
}
```

### `Resource::routes(RouteCollection $routes, string $path, string|Closure $handler, array $options = []): void` (static)

Registers every method (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`) of the resource for `$path` and `$path/(:any)`, with a single handler. The options are the usual CodeIgniter route options; only the base route gets the `as` name, so `url_to()` / `route_to()` work.

```php
Resource::routes($routes, 'api/v1/books', 'Api\Books::index', ['filter' => 'kpapy', 'as' => 'api-books']);

// In a module: the handler with its full class name
Resource::routes($routes, 'api/v1/posts', '\Modules\Blog\Controllers\Api\Posts::index', ['filter' => 'kpapy']);
```

The path may contain placeholders (`api/v1/authors/(:num)/books`): the part after it is the resource's own path.

### `setBasePath(string $path): static`

Base path of the resource when it is not registered with `Resource::routes()` (e.g. in tests or with routes of your own). By default, the matched route.

---

## Data source

### `setTable(string $table): static`

Works directly on a table. The primary key, the `created_at` / `updated_at` timestamps and the soft deletes (`deleted_at`) are detected from its structure.

### `setModel(Model|string $model): static`

Works with a CodeIgniter model: its table, primary key, `allowedFields` (fields that can be written), validation rules and messages, timestamps, soft deletes and callbacks (inserts and updates go through the model).

### `setDatabase(BaseConnection|string $db): static` · `setPrimaryKey(string $key): static`

Database group or connection, and the primary key when it cannot be detected.

### `where(array|string $field, mixed $value = null): static`

Limits **every** operation: list, details, writes, deletion, custom operations and bulk actions. A record outside it is `404`. Equality conditions are also **enforced on write**: the field cannot be sent by the client and is always saved with that value.

```php
$api->where('user_id', auth()->id());          // own records
$api->where(['status' => 'published']);
$api->where('year >=', 2000);                  // only filters (not enforced on write)
```

### `softDeletes(bool $enabled = true, string $field = 'deleted_at'): static`

Enables or disables the trash in table mode (in model mode the model decides). With soft deletes, `DELETE` moves the record to the trash, `?trashed=` shows it, `POST /{id}/restore` restores it and `DELETE /{id}?purge=1` deletes it permanently.

---

## Fields

### `fields(array $fields): static`

Fields returned in the details (and in the list, unless `listFields()`). They can include virtual fields computed with `callbackField()`, N-N relations and translated fields. The primary key is always included. Default: every column except the soft-delete field and the sensitive fields, plus the translated fields and the N-N relations.

### `listFields(array $fields): static`

Fields of the list when they differ from the details (e.g. without long texts). The client can still ask for any field of the details with `?fields=`.

### `writeFields(array $fields): static` · `addFields(array $fields): static` · `editFields(array $fields): static`

Fields the client may send, for both operations or separately for create and update. Default: the model's `allowedFields` (model mode) or every column except the primary key, the timestamps, the soft-delete field and the sensitive fields (passwords are allowed), plus translated fields and N-N relations. **Anything else in the body is ignored** (mass-assignment protection), or rejected with `422` if `Config\Kpapy::$strictInput` is `true`.

### `hideFields(array $fields): static`

Removes fields from everything: output, `?fields=`, filters, search, sorting, includes and input (they are never saved). It takes priority over the other lists, so it can be called at the end depending on a permission:

```php
if (! auth()->user()->can('staff.salary')) {
    $api->hideFields(['salary', 'bank_account']);
}
```

### `readonlyFields(array $fields): static`

Fields that can be sent when creating but not when updating.

### `requiredFields(array $fields): static` · `setDefault(string $field, mixed $value): static`

Required fields (also translated fields), and the value used when creating if the client does not send the field (`$value` may be a closure).

### `setRules(array|string $field, array|string|null $rules = null, array $messages = []): static`

CodeIgniter validation rules. `{id}` is replaced by the real id when updating. Without rules, they are **inferred** from the table (`inferRules`): `NOT NULL` without default → `required`; `VARCHAR(n)` → `max_length[n]`; integer → `integer`; decimal → `decimal`; float → `numeric`; a field named `email` → `valid_email`; `url`/`*_url`/`website` → `valid_url_strict[http,https]`.

```php
$api->setRules('isbn', 'permit_empty|max_length[20]|is_unique[books.isbn,id,{id}]', ['is_unique' => 'This ISBN already exists.']);
```

Validation runs on every field when creating and with `PUT`, and only on the fields sent with `PATCH`.

### `fieldType(string $field, string $type, array $options = []): static`

Forces the type of a field (otherwise it is deduced from the database and the name):

| Type | Output | Input | Options |
|---|---|---|---|
| `int`, `float` | number | number | |
| `decimal` | string (`"12.50"`) | number or numeric string | `scale` (decimals of the output) |
| `bool` | `true` / `false` | `true`, `false`, `1`, `0`, `"true"`, `"false"` | (`TINYINT(1)` is detected) |
| `date` | `"2026-11-03"` | ISO 8601 date | |
| `datetime` | ISO 8601 with time zone | ISO 8601 (converted to the app time zone) | |
| `json` | decoded JSON | any JSON value | |
| `enum` / `select` | string | one of the option keys | `options` => `[value => label]` |
| `multiselect` | array of strings | array (or a JSON/CSV string) of option keys | `options`, `storage` => `json` (default) or `csv` |
| `password` | never returned | text, stored with `password_hash()`; empty when updating = unchanged | `hash` (default `true`) |
| `upload`, `image` | URL | multipart file | see [Files](#files) |
| `virtual` | from `callbackField()` | — | |

### `callbackField(string $field, Closure $callback): static`

Output value of a field, or of a virtual one: `fn (mixed $value, array $row): mixed`.

```php
$api->fields(['id', 'name', 'books'])
    ->callbackField('books', fn ($value, array $row) => model(BookModel::class)->where('author_id', $row['id'])->countAllResults());
```

### `transform(Closure $callback): static`

Transforms each output item: `fn (array $item, array $row): array` (`$row` is the database row).

---

## Relations

### `setRelation(string $field, string $table, array|string $columns, array $options = []): static`

1-N relation (`books.author_id → authors.id`). The field is returned as the related id (masked with the related table as context); with `?include=<as>` the related record is added as an object with the given columns. On input, the id must exist (and match `where`).

Options: `as` (name for `?include=`, by default the field without `_id`), `pk` (default `id`), `where` (allowed records).

```php
$api->setRelation('author_id', 'authors', ['name', 'country'], ['as' => 'author']);
// GET /books/{id}?include=author → "author": {"id": "…", "name": "…", "country": "…"}
```

### `setRelationNN(string $name, string $junction, string $otherTable, string $thisKey, string $otherKey, array|string $columns, array $options = []): static`

N-N relation through a junction table. `$name` is a virtual field returned as a list of ids, or as objects with `?include=<name>`; it is written by sending the list of ids (the junction rows are replaced in a transaction). It can be filtered: `?filter[tags]=3,5` (records with any of them).

```php
$api->setRelationNN('tags', 'book_tag', 'tags', 'book_id', 'tag_id', ['name']);
```

---

## Translations

### `setTranslations(string $table, string $foreignKey, string $localeField, array $fields, array $locales, array $options = []): static`

Translated fields stored in a child table, one row per language (as kpcrudy's `setTranslations()`). Options: `required` (languages that must have the fields; default, the first one).

```php
$api->setTranslations('book_translations', 'book_id', 'locale', ['summary'], ['en', 'ca', 'es'], ['required' => ['en']]);
```

- **Output** in the requested language: `?lang=ca`, otherwise the `Accept-Language` header (also `ca-ES` → `ca`), otherwise the first language of the chain. A missing value falls back along the chain: requested language → `Config\Kpapy::$localeFallbacks` → the app's default locale → the other languages. `?lang=*` returns every language: `"summary": {"en": "…", "ca": "…", "es": null}`.
- **Input**: a string (for the request language) or an object per language: `"summary": {"en": "…", "ca": "…"}`. Required languages must have a value when creating (if the column is `NOT NULL` without default, or the field is required) and cannot be emptied; an optional language sent empty is deleted.
- The translated fields of the request language can be searched (`q`), filtered and sorted.

---

## Listing

### Query parameters

| Parameter | Example | Notes |
|---|---|---|
| `page`, `per_page` | `?page=2&per_page=50` | `per_page` ≤ `maxPerPage` |
| `cursor` | `?cursor=WyJ…` | Only with `cursorPagination()`; the next one is in `meta.next_cursor` |
| `sort` | `?sort=-year,title` | `-` = descending; only sortable fields |
| `q` | `?q=war` | Search in `searchFields()` (permission `search`) |
| `filter[field]` | `?filter[author_id]=aB7…` | Equality (permission `filters`) |
| `filter[field][op]` | `?filter[year][gte]=1970` | Operators below |
| `fields` | `?fields=title,year` | Sparse fields (any field of the details) |
| `include` | `?include=author,tags` | Declared relations only |
| `lang` | `?lang=ca`, `?lang=*` | Translated fields |
| `trashed` | `?trashed=with`, `?trashed=only` | With soft deletes; permission `trash` |

Filter operators: `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in` (`?filter[year][in]=1962,1974`), `between` (`?filter[price][between]=10,20`), `like` (`%` and `_` are matched literally), `null`, `notnull`. Values are converted and validated with the field type (relation ids are unmasked, dates accept ISO 8601, booleans `true`/`false`/`1`/`0`); an unknown field, operator or invalid value is `400`. Multiselect fields: `?filter[audience]=teens` (records that contain it). N-N relations: `?filter[tags]=id1,id2` (records with any of them).

### `filters(array $fields): static`

Fields that can be filtered. Default: 1-N relations, `enum`/`select`, booleans and multiselect fields.

### `searchFields(array $fields): static`

Fields searched by `?q=`. Default: the text columns (and translated fields) of the output that are not relations, sensitive or computed with `callbackField()`.

### `unsortable(array $fields): static` · `defaultOrder(string $field, string $direction = 'asc'): static`

Fields that cannot be sorted, and the order when the client does not send `sort`. The primary key is always added at the end so pages are stable.

### `setPerPage(int $perPage): static`

Records per page of this resource (default `Config\Kpapy::$perPage`).

### `cursorPagination(string $field, string $direction = 'desc'): static`

Cursor pagination instead of pages, for large tables and infinite scroll: `meta.next_cursor` and `links.next` give the next page (`null` at the end). The cursor carries the value of the field and the (masked) id, so the field does not need to be unique.

### `withoutTotal(): static`

Does not count the records (very large tables): `meta.total`, `meta.pages` and `links.last` are omitted, and `links.next` is present while a page is full.

---

## Operations and HTTP

| Operation | Method and path | Success | Permission |
|---|---|---|---|
| list | `GET /r` | `200` | `list` |
| show | `GET /r/{id}` | `200` (`304` with `If-None-Match`) | `show` |
| add | `POST /r` | `201` + `Location` | `add` |
| edit | `PATCH /r/{id}` (partial), `PUT /r/{id}` (full) | `200` | `edit` (also checked with the record as it would be after the change) |
| delete | `DELETE /r/{id}` | `204` | `delete` |
| restore | `POST /r/{id}/restore` | `200` | `restore` |
| purge | `DELETE /r/{id}?purge=1` | `204` | `purge` |
| custom operation | `POST /r/{id}/{key}`, `POST /r/{key}` (or `GET`) | `200`/`201`/`204` | its `permission` |
| bulk | `POST /r/bulk` | `200` | `bulk` + per record |
| upload | `POST /r/{id}/files/{field}` | `200` | `edit` |
| download | `GET /r/{id}/files/{field}?expires=…&signature=…` | the file | signed URL |
| preflight | `OPTIONS` | `204` + `Allow` | — |

A method that is not available answers `405` with `Allow`. `bulk`, `restore` and `files` are reserved words.

**Request body.** JSON (`Content-Type: application/json`, an object) or `multipart/form-data` (needed to upload files). Larger than `maxBodySize` → `413`; invalid JSON → `400`; another content type → `415`.

**Output.** See the [response format](../README_EN.md#response-format): dates in ISO 8601 with time zone, decimals as strings, relation ids masked.

**ETags.** Every record has an `ETag` (a hash of the row); lists have the hash of the body. `If-None-Match` → `304`. `PATCH`, `PUT` and `DELETE` with `If-Match` answer `412` if the record has changed (`*` matches anything). With `Config\Kpapy::$requireIfMatch = true`, writes without `If-Match` answer `428`.

---

## Custom operations and bulk actions

### `addOperation(string $key, Closure $callback, array $options = []): static`

An operation served from the resource's own route: `POST /r/{id}/{key}` (on a record) or `POST /r/{key}` (`'row' => false`). kpapy checks the method, the masked id, `where()`, the permission and `visible` before calling it.

`fn (?string $id, ?array $row, Resource $api): mixed`, returning:

| Value | Response |
|---|---|
| `array` | `{"data": …}` with `status` (default `200`) |
| `string` | `{"data": {"message": …}}` |
| `null` or `true` | `204` |
| `false` | `409` `operation-failed` |
| `ResponseInterface` | as it is |

Options: `row` (default `true`), `method` (`post` or `get`), `permission` (default: the key), `visible` (`fn (?array $row): bool`, also blocks the operation), `trash` (works on records in the trash), `status`, `description` (OpenAPI). The key must start with a lowercase letter, contain only lowercase letters, numbers, `-` and `_`, and cannot be a reserved word or a built-in operation.

```php
$api->addOperation('lend', function (string $id, array $book, Resource $api) {
    if (! $book['available']) {
        $api->fail('The book is already on loan.');
    }
    // …
    return ['due_at' => date('Y-m-d', strtotime('+21 days'))];
}, ['status' => 201, 'description' => 'Borrow the book']);
```

### `fail(string $message, int $status = 409, string $type = 'operation-failed'): never`

Stops the operation (or a hook) with an error for the client.

### `addBulkAction(string $key, Closure $callback, array $options = []): static`

`POST /r/bulk` with `{"action": "<key>", "ids": ["…", "…"]}` (at most 1000 ids). The callback receives the real ids already filtered by `where()` and by the per-record permission: `fn (array $ids, Resource $api): mixed` (a string is returned as `message`). Options: `permission`, `description`. The built-in actions `delete`, `restore` and `purge` are always available (with their permissions). Response:

```json
{"data": {"processed": ["aB7…"], "skipped": [{"id": "x", "reason": "not-found"}], "message": "1 books updated."}}
```

`reason` is `not-found` or `forbidden`.

---

## Hooks

Same signatures as kpcrudy:

| Hook | Signature | Return |
|---|---|---|
| `beforeInsert` | `fn (array $data): mixed` | array (new data), string (`422` with that message), `false` (`409`) or `null` |
| `beforeUpdate` | `fn (array $data, string $id, array $old): mixed` | same |
| `afterInsert` | `fn (string $id, array $data): void` | |
| `afterUpdate` | `fn (string $id, array $data, array $old): void` | |
| `beforeDelete` | `fn (string $id, array $row, bool $purge): mixed` | string or `false` cancel (`409`) |
| `afterDelete` | `fn (string $id, array $row, bool $purge): void` | |
| `afterRestore` | `fn (string $id, array $row): void` | |
| `afterWrite` | `fn (string $operation, string $id, array $data, ?array $old): void` | after `insert`, `update`, `delete` and `restore` |

```php
$api->beforeInsert(fn (array $data) => $data + ['created_by' => auth()->id()])
    ->afterWrite(fn (string $op, string $id) => cache()->delete('books'));
```

---

## Permissions

Operations: `list`, `show`, `add`, `edit`, `delete`, `trash`, `restore`, `purge`, `search`, `filters`, `bulk`, plus the `permission` of custom operations and bulk actions.

**How each decision is made** (for each operation and record):

1. Without soft deletes, `trash` and `restore` are denied; `restore` and `purge` also need `trash`.
2. The **token's scopes** (if the request has a token and `checkScopes` is on): the operation must be in them.
3. The **permission resolver** answers `true`, `false` or `null`. `false` denies: the resolver has the last word on denying.
4. A `setPermission()` rule of the resource decides.
5. Otherwise, the resolver's answer; if it had no opinion, `Config\Kpapy::$denyByDefault` (`false` allows, `true` denies).

### `setPermission(string $operation, bool|Closure $allowed): static`

```php
$api->setPermission('delete', false)
    ->setPermission('edit', fn (?array $row) => $row === null || (int) $row['owner_id'] === auth()->id());
```

`$row` is `null` when the question is general (e.g. whether `POST` is available).

### `unsetList()`, `unsetShow()`, `unsetAdd()`, `unsetEdit()`, `unsetDelete()`, `unsetTrash()`, `unsetPurge()`, `unsetBulk()` · `readOnly(): static`

Shortcuts for `setPermission(..., false)`. `readOnly()` disables `add`, `edit`, `delete`, `restore`, `purge` and `bulk`.

### `setResource(string $resource): static` · `getResource(): string`

Name of the resource for the resolver and the scopes (default: the table). For CodeIgniter Shield, `<module>-<resource>` (`library-books`) gives permissions such as `library-books.edit` and lets `library-books.*` grant everything (Shield only expands the first part of a wildcard).

### Interface `Kpapy\PermissionResolver`

```php
interface PermissionResolver
{
    public function allows(string $resource, string $operation, ?array $row, Resource $api): ?bool;
}
```

Configured for every resource in `Config\Kpapy::$permissionResolver`, or for one with `setPermissionResolver()`. Example backed by Shield (`search`, `filters` and `bulk` mapped to `list`):

```php
class Permissions implements \Kpapy\PermissionResolver, \Kpcrudy\PermissionResolver
{
    public function allows(string $resource, string $operation, ?array $row, object $api): ?bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $operation = in_array($operation, ['search', 'filters', 'bulk'], true) ? 'list' : $operation;

        return $user->can("{$resource}.{$operation}");
    }
}
```

A class can implement **both** interfaces (`Kpapy\PermissionResolver` and `Kpcrudy\PermissionResolver`), so the web and the API share one source of truth (declare the last parameter as `object`). The answers without a record are cached per resource instance.

### `setPermissionResolver(PermissionResolver|Closure|null $resolver): static`

Resolver for this resource only: an instance, a closure with the same signature, or `null` to ignore the configured one.

### `isAllowed(string $operation, ?array $row = null): bool`

Whether the client can do an operation (combining scopes, resolver, rules and `denyByDefault`), e.g. inside `callbackField()`.

### `permissionCatalogue(): array`

Every operation this resource can ask about: the built-in ones that are active (`trash`, `restore` and `purge` only with soft deletes; without the ones disabled with `setPermission(..., false)`), the custom operations and the bulk actions' permissions. Useful to build or check a permission catalogue (see `php spark kpapy:routes`).

### `Resource::impliedPermissions(array $operations): array` (static) · `Resource::IMPLIED_PERMISSIONS`

Completes a list with the operations it presupposes: `add`, `delete`, `trash`, `search`, `filters`, `bulk` → `list`; `edit` → `list`, `show`; `restore`, `purge` → `trash`.

### Scopes

Tokens can be limited to some operations (`Shield` token scopes): `*`, `resource.*` or `resource.operation` (`search`, `filters` and `bulk` are also accepted with `resource.list`). A token can do the **intersection** of its scopes and the user's permissions. Requests without a token (public routes) are only subject to the permissions.

### Own records

There is no special permission:

- users that must only **see** their own records → `where('user_id', $id)`;
- users that see everything but only **change** their own → a per-record `setPermission('edit', fn (?array $row) => …)` closure or the resolver, which receive the record.

### `Kpapy\Bridge\KpcrudyResolver`

Adapts an existing kpcrudy resolver that cannot implement both interfaces:

```php
class ApiPermissions extends \Kpapy\Bridge\KpcrudyResolver
{
    public function __construct()
    {
        parent::__construct(new \App\Libraries\CrudPermissions());
    }
}
```

---

## Masked ids

### `maskIds(bool $enabled = true, ?string $context = null, IdMasker|string|null $masker = null): static`

Masks the ids of this resource (`Config\Kpapy::$maskIds` does it for every resource): the primary key in URLs and responses, relation ids (with the related table as context), cursors and bulk ids. A forged or altered code is `404`.

The codes are **identical to kpcrudy's**: same algorithm (AES-256-CTR with an HMAC of the id as IV), same key (`Config\Kpapy::$maskKey`, else `Config\Kpcrudy::$maskKey`, else CodeIgniter's encryption key, else `writable/kpcrudy/mask.key`) and same context (the table name). A record has the same id in the back office and in the API.

Without kpcrudy this works the same way: `Config\Kpcrudy::$maskKey` is simply skipped. The last fallback, `writable/kpcrudy/mask.key`, keeps kpcrudy's file name on purpose: it is only created when no key is configured at all (not even CodeIgniter's encryption key), and if kpcrudy is added later both libraries read the same file and the ids still match. To avoid the file, set `Config\Kpapy::$maskKey` (32+ characters) or `encryption.key` in `.env`.

### `encodeId(int|string $id, ?string $context = null): string` · `decodeId(string $value, ?string $context = null): ?string`

The code of an id and the other way round (the context defaults to the table).

---

## Files

`fieldType($field, 'upload'|'image', $options)` with `path` (directory; default `Config\Kpapy::$uploadPath/<table>`), `url` (public base URL; without it the files are **private**), `allowed` (extensions), `maxSize` (KB), `subdir` (string with `{field}` placeholders or `fn (array $data): string`), `deleteFiles` (default `true`).

- **Upload**: in the `multipart/form-data` body of `POST`/`PATCH`, or `POST /r/{id}/files/{field}` (field `<field>` or `file`). The extension (declared and real), the MIME type (images) and the size are checked; the file gets a random name. A replaced file is deleted.
- **Output**: the public URL, or for private files a **signed URL** to `GET /r/{id}/files/{field}?expires=…&signature=…`, valid `fileUrlLifetime` seconds (the same URL for a minute, so clients can cache it). The record must still be visible.

---

## Access from callbacks

`getTable()`, `getPrimaryKey()`, `getConfig()`, `getRequest()`, `user()` (the authenticated user, `null` on public routes), `masksIds()`, `url(?string $id = null, string $suffix = '')` (absolute URL of the resource or of a record, with the masked id).

---

## Errors

`Kpapy\Http\ApiException` becomes a `problem+json` response. It can be thrown from hooks, callbacks and custom operations:

```php
use Kpapy\Http\ApiException;

throw ApiException::conflict('The book is on loan.');
throw ApiException::validation(['isbn' => ['Invalid ISBN.']]);
throw new ApiException(402, 'payment-required', 'Upgrade your plan.');
```

Static constructors: `badRequest`, `unauthorized`, `forbidden`, `notFound`, `methodNotAllowed(array $allow)`, `conflict`, `preconditionFailed`, `preconditionRequired`, `unsupportedMediaType`, `validation(array $errors)`, `tooManyRequests(int $retryAfter)`. The title comes from `Kpapy.problems.<type>` (translated); `type` is `about:blank` or `Config\Kpapy::$problemTypeBase/<type>`.

---

## The `kpapy` filter

Registered automatically (`Kpapy\Config\Registrar`). For every API route:

| Filter | Authentication | Rate limit (per minute) |
|---|---|---|
| `kpapy` | Token required (`401` without it) | `rateLimit` per token |
| `kpapy:optional` | Token if present | per token, or `rateLimitPublic` per IP |
| `kpapy:public` | None | `rateLimitPublic` per IP |
| `kpapy:login` | None | `rateLimitLogin` per IP |
| `kpapy:hmac` | Shield HMAC token | `rateLimit` per token |

It also: answers CORS preflights (`OPTIONS`) and adds `Access-Control-Allow-Origin` (+ `Vary: Origin`, exposed headers `ETag`, `Location`, `Retry-After`) for the origins in `corsOrigins`; refuses non-HTTPS requests in production (`requireHttps`); stores the user, the scopes and the token in `Kpapy\Auth\Context`. `429` responses carry `Retry-After`.

---

## Authentication

### Interface `Kpapy\Auth\Authenticator`

```php
public function authenticate(IncomingRequest $request): ?array;   // ['user' => …, 'scopes' => list|null, 'token' => …] or null
```

`Config\Kpapy::$authenticator`: `shield` (`ShieldTokenAuthenticator`, Shield access tokens: `Authorization: Bearer <token>`), `hmac` (`ShieldHmacAuthenticator`), or the class of your own authenticator. Return `null` when the request has no credentials; throw `ApiException::unauthorized()` when they are invalid.

### `Kpapy\Auth\Context`

`Context::user()`, `Context::scopes()` (`null` without a token), `Context::token()` (the Shield access token), `Context::set()`, `Context::reset()`.

### `Kpapy\Auth\TokenService`

Shield access tokens for the web profile of the application and for the app login:

| Method | Description |
|---|---|
| `create(object $user, string $name, array $scopes = [], ?int $lifetime = null): array` | `['token', 'id', 'name', 'scopes', 'expires_at']`. The raw token can only be seen now. Empty scopes → `defaultScopes`; `$lifetime` in seconds (`null` = `tokenLifetime`, `0` = never) |
| `list(object $user): array` | `id`, `name`, `scopes`, `last_used_at`, `expires_at`, `created_at`, `current` |
| `revoke(object $user, int $id): bool` | |
| `TokenService::cleanScopes(array $scopes): array` (static) | Keeps only valid scopes |

Personal tokens are created only from the web profile (never through the API), so a leaked token cannot create others.

### `Kpapy\Auth\AuthRoutes`

```php
AuthRoutes::register($routes, 'api/v1');                       // the API endpoints below
AuthRoutes::web($routes, 'profile', ['filter' => 'session']);  // POST profile/api-logins/{id}/deny (web session + CSRF)
echo AuthRoutes::pendingLogins(auth()->id(), 'profile');       // HTML of the pending sign-ins for the profile page
```

| Endpoint | Filter | Description |
|---|---|---|
| `POST /auth/token` | `kpapy:login` | Step 1: `{"email", "password", "device_name"?, "scopes"?}` → `202` with a challenge |
| `POST /auth/token/verify` | `kpapy:login` | Step 2: `{"challenge", "code"}` → `201` `{"token", "token_type": "Bearer", "expires_at", "scopes"}` |
| `DELETE /auth/token` | `kpapy` | Sign out: revokes the current token (`204`) |
| `POST /auth/token/refresh` | `kpapy` | New token with the same name and scopes; the old one is revoked (`201`) |
| `GET /me/tokens` | `kpapy` | Tokens of the user |
| `DELETE /me/tokens/{id}` | `kpapy` | Revoke a token |

### App login (`Kpapy\Auth\LoginController`)

1. **Credentials.** Checked with Shield's password hashes (no web session is started), throttled per IP (filter) and per account (`rateLimitLogin`). Wrong → `401 invalid-credentials` (generic); banned or inactive → `403`.
2. **Second factor.** The first method of `twoFactorMethods` the user has active is used, and the response is:

```json
{"data": {"challenge": "a31b…", "method": "web", "expires_in": 300, "message": "Open your profile in the web application and enter the code shown there.", "profile_url": "https://example.com/profile"}}
```

   - `totp`: the code of the user's authenticator app;
   - `email`: a 6-digit code sent by email;
   - `web`: for users **without** 2FA. The code is shown in their **web profile** (`AuthRoutes::pendingLogins()`), where they can also deny the sign-in. Having a web session is the second factor.

   Without `web` in the list, users without 2FA get `403 two-factor-required`.
3. **Verification.** A wrong code is `422 invalid-code`; after `challengeAttempts` failures the challenge is closed. Challenges are single-use and expire after `challengeLifetime` seconds.

Requires the `kpapy_login_challenges` table (`php spark migrate -n Kpapy`). To use other settings (e.g. in a module), extend `LoginController` and redefine `setup()`.

### Two-factor providers

`Config\Kpapy::$twoFactorProviders` (method → class implementing `Kpapy\Auth\TwoFactor\TwoFactorProvider`):

```php
interface TwoFactorProvider
{
    public function isEnabledFor(object $user): bool;
    public function start(object $user, array $challenge, Challenges $challenges): string;      // message for the app
    public function verify(object $user, array $challenge, string $code, Challenges $challenges): bool;
}
```

- `EmailProvider`: enabled when Shield's email 2FA is active (`Config\Auth::$actions['login'] = Email2FA::class`); extend it and redefine `isEnabledFor()` if each user chooses.
- `TotpProvider` (RFC 6238, built in, no dependency): extend it and redefine `secretFor(object $user): ?string` (the user's base32 secret, `null` = not enabled), optionally `verifyRecoveryCode()`, and `lastStep()` / `rememberStep()` to refuse reused codes. Properties `$digits` (6), `$period` (30), `$window` (1). `codeAt(string $secret, int $step)` and `base32Decode()` are public.
- `WebCodeProvider`: always enabled (the fallback).

`Kpapy\Auth\Challenges` gives `pendingFor($userId)` and `deny($id, $userId)` for your own profile pages.

### Events

`kpapy.auth.challenge` (`user`, `method`, `device`), `kpapy.auth.login` (`user`, `token_id`, `method`), `kpapy.auth.failed` (`user` or `email`, `method`), `kpapy.auth.logout` (`user`, `token_id`), `kpapy.auth.denied` (`user_id`, `challenge`). For an activity log:

```php
\CodeIgniter\Events\Events::on('kpapy.auth.login', static function (array $data): void {
    log_message('info', 'API login of user {id}', ['id' => $data['user']->id]);
});
```

---

## OpenAPI and documentation

### `describe(string $summary, string $description = ''): static`

Name and description of the resource in the OpenAPI document. Custom operations and bulk actions use their `description` option.

### `Kpapy\OpenApi\DocsController::routes(RouteCollection $routes, string $path = 'api/v1/docs', array $options = []): void` (static)

`GET <path>`: a documentation page (HTML, no JavaScript, CSP-friendly, light and dark); `GET <path>/openapi.json`: the OpenAPI 3.1 document (for Swagger UI, Postman, code generators…). Not available in production unless `docsInProduction`. To restrict it to some paths or use another configuration, extend it and redefine `prefix()` and `kpapy()`.

### `Kpapy\OpenApi\Generator`

`generate(?string $prefix = null): array` builds the document from the routes registered with `Resource::routes()` (each resource describes itself: fields and types, required fields, filters, sorting, includes, languages, operations, errors) and the `AuthRoutes` endpoints. `Generator::toYaml(array $data)` converts it to YAML. `php spark kpapy:openapi` writes it to a file.

---

## Configuration

`app/Config/Kpapy.php` (`php spark kpapy:publish config`), class `Config\Kpapy extends \Kpapy\Config\Kpapy`:

| Property | Default | Description |
|---|---|---|
| `perPage`, `maxPerPage` | `25`, `100` | Records per page and the maximum `per_page` |
| `envelope` | `true` | `false`: a single record is returned without `{"data": …}` |
| `envelopeKeys` | `data`, `meta`, `links` | Names of the envelope keys |
| `strictInput` | `false` | `422` for fields that cannot be written |
| `maxBodySize` | `1024` | KB |
| `inferRules` | `true` | Validation rules from the table |
| `requireIfMatch` | `false` | Writes need `If-Match` (`428`) |
| `sensitiveFields` | `password`, `token`, `secret`… | Never returned unless listed |
| `maskIds`, `maskKey`, `idMasker` | `false`, `''`, `DefaultIdMasker` | Masked ids (compatible with kpcrudy) |
| `permissionResolver` | `null` | Class implementing `Kpapy\PermissionResolver` |
| `denyByDefault` | `false` | Deny what nobody allows |
| `checkScopes` | `true` | Apply the token scopes |
| `authenticator` | `shield` | `shield`, `hmac` or a class |
| `tokenLifetime` | `2592000` (30 days) | Seconds; `0` = never |
| `defaultScopes` | `['*']` | Scopes of new tokens |
| `twoFactorMethods` | `['totp', 'email', 'web']` | Order of preference |
| `twoFactorProviders` | email, totp, web | Method → class |
| `challengeLifetime`, `challengeAttempts` | `300`, `5` | Seconds and failed attempts |
| `profileUrl` | `''` | Web profile with the sign-in codes (sent to the app) |
| `requireHttps` | `true` | In production |
| `corsOrigins`, `corsHeaders`, `corsMaxAge` | `[]`, …, `3600` | CORS (`*` = any origin) |
| `rateLimit`, `rateLimitPublic`, `rateLimitLogin` | `120`, `60`, `10` | Requests per minute (`0` = no limit) |
| `uploadPath`, `uploadMaxSize` | `WRITEPATH/uploads/kpapy`, `2048` | Files |
| `uploadAllowed`, `imageAllowed` | documents and images | Extensions |
| `fileUrlLifetime` | `600` | Seconds of the signed file URLs |
| `localeFallbacks` | `[]` | Fallback languages of translated fields |
| `openApiTitle`, `openApiVersion`, `openApiDescription` | `API`, `1.0.0`, `''` | OpenAPI document |
| `docsInProduction` | `false` | Documentation page in production |
| `problemTypeBase` | `''` | Base URL of the error types |
