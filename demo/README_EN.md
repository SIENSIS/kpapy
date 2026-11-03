# kpapy · demo application

**English** · [Català](README_CA.md)

A small **library API** built with kpapy: books, authors, tags and the loans of the signed-in user, with demo users, the app login with both kinds of second factor, a **web console** to send requests and the **documentation page**. Every resource is a few lines in [src/Demo/Controllers/Api.php](../src/Demo/Controllers/Api.php).

The demo ships inside the library (`src/Demo`) and is installed in any CodeIgniter 4 project that has kpapy and **CodeIgniter Shield**. It does not copy or modify any project file, and uninstalling it leaves the project as it was.

> The console gives anyone a token for the demo users: the demo is **never** served in production unless it is installed with `--allow-production`.

## Installing it in your project

```bash
composer require siensis/kpapy codeigniter4/shield   # if they are not installed yet
php spark shield:setup                                         # only the first time Shield is installed
php spark migrate --all            # Shield and kpapy tables (kpapy_login_challenges)
php spark kpapy:demo install
```

```
The demo has been installed.
Console: http://localhost:8080/kpapy-demo
API:     http://localhost:8080/kpapy-demo/api/v1/books
Docs:    http://localhost:8080/kpapy-demo/api/v1/docs
User:    admin@kpapy.demo / kpapy-demo-admin
User:    reader@kpapy.demo / kpapy-demo-reader
```

The API routes (`kpapy-demo/api/*`) use tokens, not cookies: exclude them from CodeIgniter's CSRF filter (`'csrf' => ['except' => ['kpapy-demo/api/*']]`), as for any kpapy API. Shield also requires `Config\Security::$csrfProtection = 'session'`.

Other actions:

```bash
php spark kpapy:demo status       # tables and rows
php spark kpapy:demo reset        # the initial data again
php spark kpapy:demo uninstall    # tables, demo users (and their tokens), covers and marker
```

What the installation creates: the tables `kpapy_demo_*`, two Shield users, the marker file `writable/kpapy-demo.json` (with the demo's own masking key and the admin's TOTP secret) and, when covers are uploaded, `writable/uploads/kpapy-demo`.

## Running the project in this folder

This folder is also a CodeIgniter project prepared to try the demo and run the tests:

```bash
composer install
# .env: database settings (committed with generic values) and app.baseURL = 'http://localhost:8082/'
php spark migrate --all
php spark kpapy:demo install
php spark serve --port 8082
```

Open <http://localhost:8082>: the root redirects to `/kpapy-demo`. The project enables a strict Content-Security-Policy (`app/Config/App.php`) to show that the pages work with it, and excludes `kpapy-demo/api/*` from CSRF.

Tests (SQLite in memory): `vendor/bin/phpunit`.

## Users and their second factor

| User | Password | Role | Second factor in the app login |
|---|---|---|---|
| `admin@kpapy.demo` | `kpapy-demo-admin` | everything | **Authenticator app (TOTP)**: its "web profile" (`/kpapy-demo/profile/admin`) shows the code an app would show now, and the secret to add it to a real app |
| `reader@kpapy.demo` | `kpapy-demo-reader` | read, borrow books, return own loans | **None**: the code of each sign-in appears in its "web profile" (`/kpapy-demo/profile/reader`), where it can also be denied |

In a real application the "web profile" is the profile page of the website, behind the usual login; here it is open so the flow can be tried.

## The console — `/kpapy-demo`

- **Get a token**: quick tokens for each user, also a reader token whose **scopes** only allow listing and showing books (try a `POST`: `403`).
- **Sign in like an app**: email and password → challenge (`totp` or `web`) → open the web profile → type the code → token.
- **Request**: method, path and JSON body, with examples (includes, sparse fields, filters, search, languages, create with validation errors, the `lend` operation, my loans, a bulk action, cursor pagination, my tokens, the public catalogue). The response shows the status, the `ETag` and the body.

The console page has no inline JavaScript (an external ES module), like every page of the demo.

## The documentation — `/kpapy-demo/api/v1/docs`

The documentation page of the demo API, generated from the resources. Every endpoint has **Try it**: sign in at the top (for example as the reader: the code is in its web profile), fill in the parameters (`per_page`, `sort`, `lang`, filters…) or the prefilled JSON body and press **Send**; **Copy as curl** copies the request. See the [screenshots](../screenshots/README.md).

## API resources

All under `/kpapy-demo/api/v1/`. Permissions come from one resolver, [Libraries/DemoPermissions.php](../src/Demo/Libraries/DemoPermissions.php), with `denyByDefault`.

| Path | Resource | Shows |
|---|---|---|
| `books` | `library-books` | 1-N author (`?include=author`), N-N tags, summary translated into en/ca/es (`?lang=`, `Accept-Language`, `?lang=*`), multiselect `audience`, private `cover` image with signed URLs, rules (unique ISBN), filters with operators, search, soft deletes (trash, restore, purge), custom operation `POST books/{id}/lend` (only for available books), bulk action `mark-unavailable` |
| `authors` | `library-authors` | Cursor pagination (`meta.next_cursor`), computed field `books`, a hook that prevents deleting authors with books |
| `tags` | `library-tags` | The simplest resource |
| `my/loans` | `library-loans` | **Own records** (`where(user_id)`), read only, custom operation `POST my/loans/{id}/return` |
| `public/books` | `library-public` | Public catalogue **without a token** (`kpapy:public`), only available books |
| `auth/token`, `auth/token/verify`, `auth/token/refresh`, `me/tokens` | — | App login with 2FA, refresh, tokens of the user |
| `docs`, `docs/openapi.json`, `docs/try-it.js` | — | Documentation page with "Try it" and OpenAPI 3.1 document |

Every id is **masked** (with the demo's own key): copy them from the responses.

## How it is built

| Element | File |
|---|---|
| Command `kpapy:demo` | [src/Commands/Demo.php](../src/Commands/Demo.php) |
| Install and uninstall | [src/Demo/Installer.php](../src/Demo/Installer.php) |
| Names, routes, users | [src/Demo/Demo.php](../src/Demo/Demo.php) · [src/Config/Routes.php](../src/Config/Routes.php) |
| kpapy settings of the demo (masked ids, resolver, 2FA methods) | [src/Demo/DemoConfig.php](../src/Demo/DemoConfig.php) |
| API resources | [src/Demo/Controllers/Api.php](../src/Demo/Controllers/Api.php) |
| App login with the demo settings | [src/Demo/Controllers/DemoLogin.php](../src/Demo/Controllers/DemoLogin.php) |
| Permissions and TOTP | [src/Demo/Libraries](../src/Demo/Libraries) |
| Console and web profiles | [src/Demo/Controllers/Console.php](../src/Demo/Controllers/Console.php) · [src/Demo/Views](../src/Demo/Views) |
| Migrations and data | [src/Demo/Database](../src/Demo/Database) |
