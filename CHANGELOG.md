# Changelog

All notable changes to kpapy. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [semantic versioning](https://semver.org/).
Documentation is in English and Catalan: [README_EN.md](README_EN.md) · [README_CA.md](README_CA.md).

## [Unreleased]

### Added

- **Resources** (`Kpapy\Resource`) from a table or a model with a fluent API in the style of kpcrudy:
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
- **Permissions**: `PermissionResolver` with the same semantics as kpcrudy (one class can implement both),
  `setResource()`, `setPermission()`, `unset*()`, `readOnly()`, `isAllowed()`, `permissionCatalogue()`,
  `impliedPermissions()`, `denyByDefault`, and `Kpapy\Bridge\KpcrudyResolver`.
- **Masked ids** identical to kpcrudy's (same algorithm, key and context).
- The **`kpapy` filter**: Shield access tokens or HMAC tokens, public and optional modes, token scopes
  intersected with permissions, CORS, rate limits per token, per IP and for the login, HTTPS in production.
- **App login with two-factor authentication** (`AuthRoutes::register()`): the user's active method
  (TOTP authenticator app, email code) or, for users without 2FA, a code shown in their web profile
  (`AuthRoutes::pendingLogins()`, `AuthRoutes::web()`); token refresh, logout, `me/tokens`;
  `TokenService` for personal tokens in the web profile; `TwoFactorProvider` and `TotpProvider` extension
  points; `kpapy.auth.*` events.
- **OpenAPI 3.1** generated from the resources (`Generator`, `DocsController` with a CSP-friendly
  documentation page and the JSON document).
- **Commands**: `kpapy:make` (also into modules), `kpapy:openapi`, `kpapy:routes`, `kpapy:token`,
  `kpapy:publish`, `kpapy:demo`.
- **Demo**: a library API with Shield demo users (TOTP and web-code sign-in), central permissions, own
  records, a public catalogue, a web console and the documentation, installed with
  `php spark kpapy:demo install`.
- PHPUnit tests in `demo/tests/unit/Kpapy`; documentation in Catalan and English.

## Publishing

The library is published on [Packagist](https://packagist.org/packages/siensis/kpapy) and installed
with `composer require siensis/kpapy:dev-main`. Next step: tag releases (`v1.0.0`, …) following
semantic versioning (with the Packagist GitHub hook, new tags are published automatically). Then
projects can `composer require siensis/kpapy` (`^1.0`) and get updates with `composer update`.
