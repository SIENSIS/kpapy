# Recipes

**English** · [Català](howto_CA.md)

Short solutions to common cases. The full reference is in [functions_EN.md](functions_EN.md).

## Contents

- [A public, read-only API](#a-public-read-only-api)
- [Mobile app login with two-factor authentication](#mobile-app-login-with-two-factor-authentication)
- [Pending sign-ins in the web profile (users without 2FA)](#pending-sign-ins-in-the-web-profile-users-without-2fa)
- [A TOTP provider that reads the secret from your application](#a-totp-provider-that-reads-the-secret-from-your-application)
- [Your own login controller](#your-own-login-controller)
- [Personal tokens in the web profile](#personal-tokens-in-the-web-profile)
- [Mark tokens and see where they are used](#mark-tokens-and-see-where-they-are-used)
- [Scopes for integrations](#scopes-for-integrations)
- [Which tokens can manage tokens (me/tokens)](#which-tokens-can-manage-tokens-metokens)
- [One permission resolver for the whole API](#one-permission-resolver-for-the-whole-api)
- [Own records](#own-records)
- [Uploads and private files](#uploads-and-private-files)
- [Store uploads through your own storage service](#store-uploads-through-your-own-storage-service)
- [Translations and Accept-Language](#translations-and-accept-language)
- [CORS for a single-page application](#cors-for-a-single-page-application)
- [Versioning: api/v1, api/v2](#versioning-apiv1-apiv2)
- [OpenAPI document and documentation page](#openapi-document-and-documentation-page)
- [Describe your own endpoints in the OpenAPI document](#describe-your-own-endpoints-in-the-openapi-document)
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

### Your own login controller

To change one step of the app login (e.g. log the sign-in, refuse some devices or set your own configuration), extend `LoginController` and pass it to `AuthRoutes::register()`; the routes stay the same:

```php
namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Auth\LoginController;

class Login extends LoginController
{
    public function verify(): ResponseInterface
    {
        $response = parent::verify();

        if ($response->getStatusCode() === 201) {
            log_message('info', 'New API sign-in from {ip}', ['ip' => $this->request->getIPAddress()]);
        }

        return $response;
    }
}
```

```php
// app/Config/Routes.php
\Kpapy\Auth\AuthRoutes::register($routes, 'api/v1', [], \App\Controllers\Api\Login::class);
```

Or for every call, in `app/Config/Kpapy.php`: `public string $loginController = \App\Controllers\Api\Login::class;`. The fifth parameter (or `$tokenController`) does the same for `me/tokens` with a subclass of `Kpapy\Auth\TokenController`. The OpenAPI document still lists the sign-in endpoints.

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

### Mark tokens and see where they are used

Personal tokens (created in the web profile) and app tokens (created by the app login) are both Shield access tokens. Mark the personal ones with the `kind` option, so the profile page only lists them and other code can tell them apart (e.g. keep them when the user changes the password):

```php
use Kpapy\Auth\Context;
use Kpapy\Auth\TokenService;

$service = new TokenService();
$token   = $service->create(auth()->user(), 'ERP', ['library-books.list'], 90 * DAY, ['kind' => 'personal']);

$personal = $service->list(auth()->user(), 'personal');            // only the personal tokens
$isPersonal = TokenService::kindOf(Context::token()) === 'personal'; // inside an API request
```

The mark is stored in the `secret2` column of Shield's `auth_identities` table (empty for every other access token), so a query on that table works too: `->where('type', 'access_token')->where('secret2', 'personal')`.

To show the user from where each token was last used, enable the last IP address and run the library migration:

```php
// app/Config/Kpapy.php
public bool $trackTokenIps = true;
```

```bash
php spark migrate -n Kpapy      # creates kpapy_token_usage
```

`$service->list($user)` and `GET me/tokens` then return `last_ip` for every token used since then:

```json
{"data": [{"id": 12, "name": "ERP", "scopes": ["library-books.list"], "last_used_at": "2026-11-09T10:02:11+00:00", "kind": "personal", "last_ip": "203.0.113.7", "current": false}]}
```

To keep the history somewhere else (your activity log, a list of IP addresses per token…), listen to the event, which is fired on every request authenticated with a token whether `trackTokenIps` is on or not:

```php
// app/Config/Events.php
Events::on('kpapy.token.used', static function (array $data): void {
    // $data: user, token, token_id, ip, user_agent
    model(\App\Models\TokenIpModel::class)->remember($data['token_id'], $data['ip'], $data['user_agent']);
});
```

Behind a proxy, set `Config\App::$proxyIPs` so `ip` is the client's address.

### Scopes for integrations

A token for an ERP that only reads books and authors:

```php
(new TokenService())->create($user, 'ERP', ['library-books.list', 'library-books.show', 'library-authors.*']);
```

Even if the user is an administrator, that token gets `403` on any write. Scopes only restrict; they never give more than the user's permissions.

### Which tokens can manage tokens (me/tokens)

`GET me/tokens` and `DELETE me/tokens/{id}` let a client list and revoke the tokens of its user. Since 1.1 a token with limited scopes needs the `tokens.manage` scope for them, so a token leaked from an integration cannot revoke the user's other tokens nor learn their names:

```php
$service = new TokenService();

$service->create($user, 'ERP', ['library-books.list']);                   // GET me/tokens → 403
$service->create($user, 'Admin tool', ['library-books.*', 'tokens.manage']); // can list and revoke
$service->create($user, 'Phone', ['*']);                                  // unrestricted: can too
```

The app login creates tokens with `defaultScopes` (`['*']` by default), so apps keep working. An app that asks for limited scopes at sign-in adds the scope itself: `{"email": …, "scopes": ["library-books.list", "tokens.manage"]}`.

Rename the scope or go back to the 1.0 behaviour (any token of the user) in `app/Config/Kpapy.php`:

```php
public string $tokensScope = 'account.tokens';    // also accepts account.*
public bool $tokensScopeRequired = false;         // 1.0 behaviour: not recommended
```

### One permission resolver for the whole API

```php
namespace App\Libraries;

use Kpapy\Resource;

class Permissions implements \Kpapy\PermissionResolver
{
    public function allows(string $resource, string $operation, ?array $row, Resource $api): ?bool
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
// app/Config/Kpapy.php
public ?string $permissionResolver = \App\Libraries\Permissions::class;
public bool $denyByDefault         = true;
```

Each resource names its permissions with `setResource('library-books')`: the Shield permission `library-books.edit` (or the wildcard `library-books.*`) lets a user edit books through the API. `php spark kpapy:routes` lists the permissions every resource asks about.

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

### Store uploads through your own storage service

When the application already has a storage service (private disk, random names, allow-lists, S3…), let kpapy hand the files to it instead of writing them to `writable/uploads/kpapy`. Write an upload handler:

```php
namespace App\Storage;

use CodeIgniter\HTTP\Files\UploadedFile;
use Kpapy\Storage\UploadHandler;
use Kpapy\Storage\UploadReader;

/**
 * Upload fields of the API through service('storage').
 * Field options: 'disk' ('private' by default) and 'folder'.
 */
class ApiStorageHandler implements UploadHandler, UploadReader
{
    public function store(UploadedFile $file, string $field, array $context): string
    {
        $disk   = $context['options']['disk'] ?? 'private';
        $folder = $context['options']['folder'] ?? $context['resource'] . '/' . $field;

        // Your service decides the name and the location; kpapy saves what it returns in the column
        return service('storage')->store($file, $disk, $folder);
    }

    public function delete(string $stored, string $field, array $context): void
    {
        service('storage')->delete($stored);            // must not fail if it is already gone
    }

    public function url(string $stored, string $field, array $context): ?string
    {
        // Public files: their URL. Private files: null, kpapy gives a signed, temporary URL
        return ($context['options']['disk'] ?? 'private') === 'public' ? service('storage')->url($stored) : null;
    }

    public function read(string $stored, string $field, array $context): ?string
    {
        return service('storage')->read($stored);       // content of a private file, or null
    }
}
```

Use it for every resource or for one field:

```php
// app/Config/Kpapy.php
public string $uploadHandler = \App\Storage\ApiStorageHandler::class;
```

```php
$api->fieldType('contract', 'upload', ['allowed' => ['pdf'], 'maxSize' => 4096])                // private (signed URLs)
    ->fieldType('photo', 'image', ['handler' => new ApiStorageHandler(), 'disk' => 'public']);  // public URL
```

How it works:

- **Validation.** kpapy still checks the extension, the real type, images and `maxSize` before calling `store()`. If your service has its own rules, implement `Kpapy\Storage\UploadValidator` as well and return the error message from `validate()`: kpapy then calls only yours.
- **Private files.** `url()` returns `null`, and because the handler implements `UploadReader`, the API outputs a signed URL to `GET /api/v1/<resource>/{id}/files/<field>?expires=…&signature=…`, which kpapy serves with `read()` after checking the signature and that the record is still visible. Without `UploadReader`, a private field is output as `null` (give the files a URL of your own with `callbackField()`).
- **Deletion.** kpapy calls `delete()` when a file is replaced, when the record is deleted permanently (not when it goes to the trash; `deleteFiles => false` keeps them) and when saving the record fails after storing the file.
- **Context.** `$context` has `resource`, `table`, `id` (`null` while creating), `record`, `options` (the field's options, including your own keys like `disk`), `type`, `config` and `api`; use it to choose folders per resource, per owner (`$context['record']['user_id']`) or per tenant.

The same class works for kpcrudy (same interface), so the back office and the API store files in the same place.

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

`/api/v1/docs` is a readable page and `/api/v1/docs/openapi.json` the document for Swagger UI, Postman or code generators. Each endpoint of the page has **Try it** (outside production): fill in the parameters, adjust the prefilled JSON body and send the request with a token pasted in the panel at the top or after signing in there like an app; **Copy as curl** gives the same request for a terminal. To offer it in production only to some people, protect the route and enable it:

```php
// app/Config/Kpapy.php
public bool $docsInProduction = true;
public ?bool $docsTryIt = true;
```

```php
\Kpapy\OpenApi\DocsController::routes($routes, 'api/v1/docs', ['filter' => 'group:admin']);
```

Add descriptions with `->describe('Books', 'Books of the library')` and the `description` option of custom operations. Build a static file in your deployment:

```bash
php spark kpapy:openapi --prefix api/v1 --output public/openapi.json
```

### Describe your own endpoints in the OpenAPI document

A plain controller such as `GET api/v1/me` is not a resource, so the generator does not know what it returns. Describe it with the `openapi` route option (an OpenAPI Operation Object):

```php
// app/Config/Routes.php
$routes->get('api/v1/me', 'Api\Me::index', ['filter' => 'kpapy', 'openapi' => [
    'tags'        => ['Account'],
    'summary'     => 'Current user',
    'description' => 'The user of the token and its permissions.',
    'responses'   => [
        '200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => [
            'type'       => 'object',
            'properties' => ['data' => ['type' => 'object', 'properties' => [
                'id'          => ['type' => 'integer'],
                'username'    => ['type' => 'string'],
                'email'       => ['type' => 'string', 'format' => 'email'],
                'permissions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]]],
        ]]]],
        '401' => ['$ref' => '#/components/responses/E401'],
    ],
]]);
```

The path, the method and the security come from the route (`kpapy:public` or `kpapy:optional` routes are marked as not needing a token); path parameters such as `(:num)` are added as `{p1}`; without `responses`, a `200 OK` is added. The documentation page shows it under its tag, with **Try it**, and `php spark kpapy:openapi` includes it.

When the route is declared somewhere you cannot add the option to (another package, a module), register the description from your code instead, with the full path:

```php
use Kpapy\OpenApi\Generator;

Generator::addPath('api/v1/me', 'get', ['tags' => ['Account'], 'summary' => 'Current user']);
```

Call it from a place that runs before the document is generated, e.g. right after the route in `app/Config/Routes.php`. Paths follow the `--prefix` / `DocsController::prefix()` filter like the resources.

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
