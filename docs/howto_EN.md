# Recipes

**English** · [Català](howto_CA.md)

Short solutions to common cases. The full reference is in [functions_EN.md](functions_EN.md).

## Contents

- [A public, read-only API](#a-public-read-only-api)
- [Mobile app login with two-factor authentication](#mobile-app-login-with-two-factor-authentication)
- [Pending sign-ins in the web profile (users without 2FA)](#pending-sign-ins-in-the-web-profile-users-without-2fa)
- [A TOTP provider that reads the secret from your application](#a-totp-provider-that-reads-the-secret-from-your-application)
- [Personal tokens in the web profile](#personal-tokens-in-the-web-profile)
- [Scopes for integrations](#scopes-for-integrations)
- [One permission resolver for kpcrudy and kpapy](#one-permission-resolver-for-kpcrudy-and-kpapy)
- [Own records](#own-records)
- [Uploads and private files](#uploads-and-private-files)
- [Translations and Accept-Language](#translations-and-accept-language)
- [CORS for a single-page application](#cors-for-a-single-page-application)
- [Versioning: api/v1, api/v2](#versioning-apiv1-apiv2)
- [OpenAPI document and documentation page](#openapi-document-and-documentation-page)
- [Rate limits](#rate-limits)
- [Testing an API](#testing-an-api)

---

### A public, read-only API

```php
public function catalogue(): ResponseInterface
{
    return (new Resource())
        ->setTable('books')
        ->setResource('public-books')
        ->where('published', 1)                 // only what may be public
        ->fields(['id', 'title', 'author_id', 'year'])
        ->setRelation('author_id', 'authors', ['name'], ['as' => 'author'])
        ->readOnly()
        ->unsetTrash()
        ->render();
}
```

```php
Resource::routes($routes, 'api/v1/public/books', 'Api\Catalogue::catalogue', ['filter' => 'kpapy:public']);
```

No token is needed; the requests are limited per IP (`rateLimitPublic`). If you use a permission resolver with `denyByDefault`, allow the `list` and `show` operations of `public-books` without a user.

### Mobile app login with two-factor authentication

1. Create the challenges table and register the routes:

```bash
php spark migrate -n Kpapy
```

```php
\Kpapy\Auth\AuthRoutes::register($routes, 'api/v1');
```

2. Tell kpapy where the web profile is (it is sent to the app for users without 2FA):

```php
// app/Config/Kpapy.php
public string $profileUrl = 'https://example.com/profile';
```

3. The app signs in in two steps:

```http
POST /api/v1/auth/token
Content-Type: application/json

{"email": "ana@example.com", "password": "…", "device_name": "Ana's phone"}
```

```json
{"data": {"challenge": "a31b…", "method": "totp", "expires_in": 300, "message": "Enter the code of your authenticator app."}}
```

```http
POST /api/v1/auth/token/verify
Content-Type: application/json

{"challenge": "a31b…", "code": "123456"}
```

```json
{"data": {"token": "df16…", "token_type": "Bearer", "expires_at": "2026-12-03T13:34:03+01:00", "scopes": ["*"]}}
```

`method` tells the app what to ask: `totp` (authenticator app), `email` (a code was emailed) or `web` (the code is in the web profile; open `profile_url`). Then every request sends `Authorization: Bearer <token>`. `DELETE /api/v1/auth/token` signs out and `POST /api/v1/auth/token/refresh` replaces the token before it expires.

### Pending sign-ins in the web profile (users without 2FA)

For users without two-factor authentication, the code appears in their profile. Add the list to your profile page and a route to deny a sign-in:

```php
// app/Config/Routes.php (the web part: session and CSRF)
\Kpapy\Auth\AuthRoutes::web($routes, 'profile', ['filter' => 'session']);
```

```php
<!-- app/Views/profile.php -->
<?= \Kpapy\Auth\AuthRoutes::pendingLogins(auth()->id(), 'profile') ?>
```

The view (Bootstrap 5 markup, no JavaScript) lists each pending sign-in with the device and the time, a "Show code" button and "It was not me", which denies it. To build your own markup, use `(new \Kpapy\Auth\Challenges(config('Kpapy')))->pendingFor($userId)` and `->deny($id, $userId)`.

### A TOTP provider that reads the secret from your application

kpapy does not know where your application stores the TOTP secret of each user. Extend `TotpProvider`:

```php
namespace App\Libraries;

use Kpapy\Auth\TwoFactor\TotpProvider;

class AppTotp extends TotpProvider
{
    protected function secretFor(object $user): ?string
    {
        $secret = setting()->get('User.totpSecret', 'user:' . $user->id);

        return $secret === null ? null : service('encrypter')->decrypt(base64_decode($secret));
    }

    protected function verifyRecoveryCode(object $user, string $code): bool
    {
        return model(RecoveryCodeModel::class)->useCode($user->id, $code);   // marks it as used
    }

    protected function lastStep(object $user): ?int
    {
        return setting()->get('User.totpLastStep', 'user:' . $user->id);
    }

    protected function rememberStep(object $user, int $step): void
    {
        setting()->set('User.totpLastStep', $step, 'user:' . $user->id);
    }
}
```

```php
// app/Config/Kpapy.php
public array $twoFactorProviders = [
    'totp'  => \App\Libraries\AppTotp::class,
    'email' => \Kpapy\Auth\TwoFactor\EmailProvider::class,
    'web'   => \Kpapy\Auth\TwoFactor\WebCodeProvider::class,
];
```

### Personal tokens in the web profile

Tokens for integrations are created by the user in the web profile (never through the API):

```php
use Kpapy\Auth\TokenService;

public function createToken()
{
    $token = (new TokenService())->create(
        auth()->user(),
        $this->request->getPost('name'),
        (array) $this->request->getPost('scopes'),   // e.g. ['library-books.list', 'library-books.show']
        90 * DAY,
    );

    return redirect()->back()->with('newToken', $token['token']);   // show it once
}

public function tokens(): string
{
    return view('profile/tokens', ['tokens' => (new TokenService())->list(auth()->user())]);
}

public function revokeToken(int $id)
{
    (new TokenService())->revoke(auth()->user(), $id);

    return redirect()->back();
}
```

From the console: `php spark kpapy:token ana@example.com --name ERP --scopes library-books.list --days 90`.

### Scopes for integrations

A token for an ERP that only reads books and authors:

```php
(new TokenService())->create($user, 'ERP', ['library-books.list', 'library-books.show', 'library-authors.*']);
```

Even if the user is an administrator, that token gets `403` on any write. Scopes only restrict; they never give more than the user's permissions.

### One permission resolver for kpcrudy and kpapy

> Only for projects that also use kpcrudy. Without it, implement just `Kpapy\PermissionResolver` (see the recipes above).

```php
namespace App\Libraries;

class Permissions implements \Kpcrudy\PermissionResolver, \Kpapy\PermissionResolver
{
    public function allows(string $resource, string $operation, ?array $row, object $crudOrApi): ?bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if (in_array($operation, ['search', 'filters', 'bulk'], true)) {
            $operation = 'list';
        }

        return $user->can("{$resource}.{$operation}");
    }
}
```

```php
// app/Config/Kpcrudy.php and app/Config/Kpapy.php
public ?string $permissionResolver = \App\Libraries\Permissions::class;
public bool $denyByDefault         = true;
```

Use the same `setResource('library-books')` in the CRUD and in the API resource: a grant applies to both. With masked ids enabled in both libraries and the same key, the ids are identical too.

### Own records

```php
// Users only see and change their own loans
(new Resource())->setTable('loans')->where('user_id', auth('tokens')->id())->render();

// Users see every post but only change their own
(new Resource())->setTable('posts')
    ->setPermission('edit', fn (?array $row) => $row === null || (int) $row['author_id'] === auth('tokens')->id())
    ->setPermission('delete', fn (?array $row) => $row === null || (int) $row['author_id'] === auth('tokens')->id())
    ->render();
```

Inside a resource, `$api->user()` (or `\Kpapy\Auth\Context::user()`) is the authenticated user.

### Uploads and private files

```php
$api->fieldType('cover', 'image', ['path' => WRITEPATH . 'uploads/covers', 'maxSize' => 1024])          // private
    ->fieldType('photo', 'image', ['path' => FCPATH . 'uploads/photos', 'url' => base_url('uploads/photos')]); // public
```

Upload with `multipart/form-data`:

```bash
curl -H "Authorization: Bearer $TOKEN" -F cover=@cover.jpg https://example.com/api/v1/books/aB7…/files/cover
```

A private file is returned as a signed URL that expires (`fileUrlLifetime`), which the app can use directly in an image component.

### Translations and Accept-Language

```php
$api->setTranslations('book_translations', 'book_id', 'locale', ['summary'], ['en', 'ca', 'es'], ['required' => ['en']]);
```

The app sends its language with `Accept-Language: ca` (or `?lang=ca`); a missing translation falls back to `Config\Kpapy::$localeFallbacks` and the application's default language. To edit every language at once, read with `?lang=*` and write an object: `{"summary": {"en": "…", "ca": "…", "es": "…"}}`.

### CORS for a single-page application

```php
// app/Config/Kpapy.php
public array $corsOrigins = ['https://app.example.com'];
```

The `kpapy` filter answers the preflight requests and adds the CORS headers for those origins. The SPA sends the token in the `Authorization` header (never cookies), so no CSRF token is involved.

### Versioning: api/v1, api/v2

Keep the version in the path, and register a resource under several versions while it does not change:

```php
foreach (['api/v1', 'api/v2'] as $prefix) {
    Resource::routes($routes, $prefix . '/books', 'Api\Books::index', ['filter' => 'kpapy']);
}

Resource::routes($routes, 'api/v2/authors', 'Api\V2\Authors::index', ['filter' => 'kpapy']);   // changed in v2
```

### OpenAPI document and documentation page

```php
\Kpapy\OpenApi\DocsController::routes($routes, 'api/v1/docs', ['filter' => 'session']);   // only for signed-in staff
```

`/api/v1/docs` is a readable page and `/api/v1/docs/openapi.json` the document for Swagger UI, Postman or code generators. Add descriptions with `->describe('Books', 'Books of the library')` and the `description` option of custom operations. Build a static file in your deployment:

```bash
php spark kpapy:openapi --prefix api/v1 --output public/openapi.json
```

### Rate limits

```php
// app/Config/Kpapy.php
public int $rateLimit       = 300;   // per token and minute
public int $rateLimitPublic = 30;    // per IP, routes without a token
public int $rateLimitLogin  = 5;     // per IP and per account, the app login
```

Above the limit the API answers `429` with `Retry-After`. Behind a proxy, configure `Config\App::$proxyIPs` so the real IP is used.

### Testing an API

With CodeIgniter's feature tests and Shield:

```php
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Kpapy\Auth\TokenService;

final class BooksApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = null;   // run every migration (Shield, Kpapy, App)

    public function testReadOnlyToken(): void
    {
        $users = auth()->getProvider();
        $users->save(new User(['username' => 'ana', 'email' => 'ana@example.com', 'password' => 'Secret-123-pass']));
        $user  = $users->findById($users->getInsertID());
        $token = (new TokenService())->create($user, 'test', ['library-books.list'])['token'];

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('api/v1/books')->assertStatus(200);
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')->post('api/v1/books', ['title' => 'New'])->assertStatus(403);
    }
}
```

Read the body with `json_decode((string) $result->response()->getBody(), true)`. The library's own tests (in `demo/tests/unit/Kpapy`) are complete examples, including the login with the web code.
