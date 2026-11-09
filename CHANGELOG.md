# Changelog

All notable changes to kpapy. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [semantic versioning](https://semver.org/).
Documentation is in English and Catalan: [README_EN.md](README_EN.md) · [README_CA.md](README_CA.md).

## [Unreleased]

### Added

- **`where(Closure)`**: `fn (BaseBuilder $builder, Resource $api)` adds conditions of your own (`OR`, subqueries) to every
  query of the resource (list, details, counts, writes, deletion, custom operations, bulk actions), wrapped in parentheses so
  an `OR` cannot widen the scope; values are bound by the Query Builder. Not enforced on write.

## [1.1.0] - 2026-11-09

### Security

- **`GET me/tokens` and `DELETE me/tokens/{id}` now require the `tokens.manage` scope** (behaviour
  change). Until now any token of a user, even one limited to e.g. `books.list`, could list the user's
  other tokens and revoke them. Now a token needs unrestricted scopes (`*`), the scope
  `Config\Kpapy::$tokensScope` (default `tokens.manage`) or its wildcard (`tokens.*`); otherwise the
  answer is `403`. Tokens created by the app login with the default scopes (`['*']`) and requests without
  a token are not affected. Set `Config\Kpapy::$tokensScopeRequired = false` to restore the previous
  behaviour.

### Added

- **Pluggable storage for upload fields**: the `Kpapy\Storage\UploadHandler` interface (`store()`,
  `delete()`, `url()`; the same as in kpcrudy), the optional `UploadReader` (serve private files with
  signed URLs) and `UploadValidator` (replace kpapy's file checks) interfaces, and
  `Kpapy\Storage\LocalUploadHandler`, the default, which keeps the previous behaviour. Configured with
  `Config\Kpapy::$uploadHandler` or the `handler` option of `fieldType()`.
- `AuthRoutes::register()` takes the login controller and the `me/tokens` controller as optional
  parameters (subclasses of `LoginController` / `TokenController`), also configurable with
  `Config\Kpapy::$loginController` and `$tokenController`.
- **Own endpoints in the OpenAPI document**: the `openapi` route option and
  `Kpapy\OpenApi\Generator::addPath()` (and `clearPaths()`) describe plain controllers such as
  `GET api/v1/me`; path parameters, a default response and the security of public routes are added.
- `TokenService::create()` takes an `$options` array: `kind` marks a token (stored in the `secret2`
  column of Shield's `auth_identities`, unused for access tokens). `TokenService::list()` takes an
  optional `$kind` filter, `TokenService::kindOf()` reads the mark, and `POST auth/token/refresh` keeps it.
- The `kpapy.token.used` event (`user`, `token`, `token_id`, `ip`, `user_agent`) on every request
  authenticated with a token, and, with `Config\Kpapy::$trackTokenIps = true`, the last IP address of
  each token in the new `kpapy_token_usage` table (migration `CreateTokenUsage`,
  `php spark migrate -n Kpapy`), returned as `last_ip`.
- `TokenService::list()` and `GET me/tokens` return `kind` and `last_ip` (`null` when unknown).

### Changed

- The download of private files (`GET /{id}/files/{field}`) detects the MIME type from the content and
  goes through the upload handler; the default handler only serves files inside the field's directory
  (symbolic links out of it are refused).

## [1.0.1] - 2026-11-06

### Fixed

- `POST auth/token` with an unknown email answered 500 about 6 % of the time instead of 401: the
  dummy password used to keep the response time constant came from `random_bytes(16)`, and bcrypt
  throws a `ValueError` when it contains a null byte. It is now hex-encoded. Besides the error,
  the 500 hinted that the account did not exist and skipped the `kpapy.auth.failed` event.

## [1.0.0] - 2026-11-03

### Added

- **Resources** (`Kpapy\Resource`) from a table or a model with a fluent API:
  fields, list fields, write fields (mass-assignment protection, `strictInput`), `hideFields()`,
  read-only, required and default values, validation rules (also inferred from the table), field types
  (int, float, decimal, bool, date, datetime, json, enum, multiselect, password, upload/image, virtual),
  `callbackField()` and `transform()`.
- **Listing**: pagination by page or by cursor, `withoutTotal()`, filters with the operators `eq`, `ne`,
  `lt`, `lte`, `gt`, `gte`, `in`, `between`, `like`, `null` and `notnull`, search, sorting, sparse fields,
  includes of 1-N and N-N relations, trash (`?trashed=`).
- **Operations**: `GET`, `POST`, `PATCH` (partial), `PUT` (full), `DELETE` (trash or permanent), restore and
  purge, custom operations (`addOperation()`), bulk actions (`addBulkAction()` and the built-in delete,
  restore and purge), hooks (`before*`, `after*`, `afterWrite`), `fail()`.
- **Translated fields** in a child table (`setTranslations()`), with `?lang=`, `Accept-Language`, a fallback
  chain and `?lang=*`.
- **Files**: uploads in multipart requests or `POST /{id}/files/{field}`, public URLs or signed, expiring URLs
  for private files.
- **Responses** with a simple envelope (`data`, `meta`, `links`), errors as RFC 9457
  `application/problem+json` translated into six languages, ETags, conditional `GET` (`304`) and optimistic
  concurrency with `If-Match` (`412`, `428` with `requireIfMatch`).
- **Permissions**: a central `PermissionResolver`,
  `setResource()`, `setPermission()`, `unset*()`, `readOnly()`, `isAllowed()`, `permissionCatalogue()`,
  `impliedPermissions()`, `denyByDefault`.
- **Masked ids** (AES-256-CTR, deterministic per table, replaceable `IdMasker`).
- The **`kpapy` filter**: Shield access tokens or HMAC tokens, public and optional modes, token scopes
  intersected with permissions, CORS, rate limits per token, per IP and for the login, HTTPS in production.
- **App login with two-factor authentication** (`AuthRoutes::register()`): the user's active method
  (TOTP authenticator app, email code) or, for users without 2FA, a code shown in their web profile
  (`AuthRoutes::pendingLogins()`, `AuthRoutes::web()`); token refresh, logout, `me/tokens`;
  `TokenService` for personal tokens in the web profile; `TwoFactorProvider` and `TotpProvider` extension
  points; `kpapy.auth.*` events.
- **OpenAPI 3.1** generated from the resources (`Generator`, `DocsController` with a CSP-friendly
  documentation page and the JSON document).
- **Try it** on the documentation page (`docsTryIt`, by default only outside production): a form per
  endpoint with its parameters and a JSON body prefilled from the schema (`OpenApi\Example`), real
  requests with a pasted token or after signing in like an app with the second factor, status, headers
  and body of the response, and **Copy as curl**; external script, compatible with a strict CSP.
- **Commands**: `kpapy:make` (also into modules), `kpapy:openapi`, `kpapy:routes`, `kpapy:token`,
  `kpapy:publish`, `kpapy:demo`.
- **Demo**: a library API with Shield demo users (TOTP and web-code sign-in), central permissions, own
  records, a public catalogue, a web console and the documentation, installed with
  `php spark kpapy:demo install`.
- PHPUnit tests in `demo/tests/unit/Kpapy`; documentation in Catalan and English.

## Publishing

Published on [Packagist](https://packagist.org/packages/siensis/kpapy) from the Git tags
(`vX.Y.Z`, semantic versioning). Install with `composer require siensis/kpapy` (`^1.0`) and update
with `composer update siensis/kpapy`. The Composer package does not include the test application
(`demo/`) nor the screenshots (`.gitattributes`); the documentation (`docs/`) and the integrated
demo (`src/Demo`) are included.

[Unreleased]: https://github.com/SIENSIS/kpapy/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/SIENSIS/kpapy/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/SIENSIS/kpapy/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/SIENSIS/kpapy/releases/tag/v1.0.0
