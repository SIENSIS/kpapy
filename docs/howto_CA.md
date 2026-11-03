# Receptes

[English](howto_EN.md) · **Català**

Solucions breus per a casos habituals. La referència completa és a [functions_CA.md](functions_CA.md).

## Índex

- [Una API pública de només lectura](#una-api-pública-de-només-lectura)
- [Inici de sessió d'una app mòbil amb segon factor](#inici-de-sessió-duna-app-mòbil-amb-segon-factor)
- [Inicis de sessió pendents al perfil web (usuaris sense 2FA)](#inicis-de-sessió-pendents-al-perfil-web-usuaris-sense-2fa)
- [Un proveïdor TOTP que llegeix el secret de l'aplicació](#un-proveïdor-totp-que-llegeix-el-secret-de-laplicació)
- [Tokens personals al perfil web](#tokens-personals-al-perfil-web)
- [Abasts per a integracions](#abasts-per-a-integracions)
- [Un sol resolutor de permisos per a kpcrudy i kpapy](#un-sol-resolutor-de-permisos-per-a-kpcrudy-i-kpapy)
- [Registres propis](#registres-propis)
- [Pujada de fitxers i fitxers privats](#pujada-de-fitxers-i-fitxers-privats)
- [Traduccions i Accept-Language](#traduccions-i-accept-language)
- [CORS per a una aplicació d'una sola pàgina](#cors-per-a-una-aplicació-duna-sola-pàgina)
- [Versions: api/v1, api/v2](#versions-apiv1-apiv2)
- [Document OpenAPI i pàgina de documentació](#document-openapi-i-pàgina-de-documentació)
- [Límits de peticions](#límits-de-peticions)
- [Fer tests d'una API](#fer-tests-duna-api)

---

### Una API pública de només lectura

```php
public function catalogue(): ResponseInterface
{
    return (new Resource())
        ->setTable('books')
        ->setResource('public-books')
        ->where('published', 1)                 // només el que pot ser públic
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

No cal token; les peticions es limiten per IP (`rateLimitPublic`). Si fas servir un resolutor de permisos amb `denyByDefault`, permet les operacions `list` i `show` de `public-books` sense usuari.

### Inici de sessió d'una app mòbil amb segon factor

1. Crea la taula dels reptes i registra les rutes:

```bash
php spark migrate -n Kpapy
```

```php
\Kpapy\Auth\AuthRoutes::register($routes, 'api/v1');
```

2. Indica on és el perfil web (s'envia a l'app per als usuaris sense 2FA):

```php
// app/Config/Kpapy.php
public string $profileUrl = 'https://example.com/perfil';
```

3. L'app inicia la sessió en dos passos:

```http
POST /api/v1/auth/token
Content-Type: application/json

{"email": "ana@example.com", "password": "…", "device_name": "Mòbil de l'Ana"}
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

`method` diu a l'app què ha de demanar: `totp` (aplicació d'autenticació), `email` (s'ha enviat un codi per correu) o `web` (el codi és al perfil web; obre `profile_url`). Després, cada petició porta `Authorization: Bearer <token>`. `DELETE /api/v1/auth/token` tanca la sessió i `POST /api/v1/auth/token/refresh` substitueix el token abans que caduqui.

### Inicis de sessió pendents al perfil web (usuaris sense 2FA)

Per als usuaris sense segon factor, el codi apareix al seu perfil. Afegeix-hi la llista i una ruta per denegar un inici de sessió:

```php
// app/Config/Routes.php (la part web: sessió i CSRF)
\Kpapy\Auth\AuthRoutes::web($routes, 'profile', ['filter' => 'session']);
```

```php
<!-- app/Views/profile.php -->
<?= \Kpapy\Auth\AuthRoutes::pendingLogins(auth()->id(), 'profile') ?>
```

La vista (marques de Bootstrap 5, sense JavaScript) mostra cada inici de sessió pendent amb el dispositiu i l'hora, un botó "Mostra el codi" i "No he estat jo", que el denega. Per fer-ne unes marques pròpies, fes servir `(new \Kpapy\Auth\Challenges(config('Kpapy')))->pendingFor($userId)` i `->deny($id, $userId)`.

### Un proveïdor TOTP que llegeix el secret de l'aplicació

kpapy no sap on desa la teva aplicació el secret TOTP de cada usuari. Estén `TotpProvider`:

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
        return model(RecoveryCodeModel::class)->useCode($user->id, $code);   // el marca com a usat
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

### Tokens personals al perfil web

Els tokens per a integracions els crea l'usuari al perfil web (mai des de l'API):

```php
use Kpapy\Auth\TokenService;

public function createToken()
{
    $token = (new TokenService())->create(
        auth()->user(),
        $this->request->getPost('name'),
        (array) $this->request->getPost('scopes'),   // p. ex. ['library-books.list', 'library-books.show']
        90 * DAY,
    );

    return redirect()->back()->with('newToken', $token['token']);   // es mostra un sol cop
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

Des de la consola: `php spark kpapy:token ana@example.com --name ERP --scopes library-books.list --days 90`.

### Abasts per a integracions

Un token per a un ERP que només llegeix llibres i autors:

```php
(new TokenService())->create($user, 'ERP', ['library-books.list', 'library-books.show', 'library-authors.*']);
```

Encara que l'usuari sigui administrador, aquest token rep `403` a qualsevol escriptura. Els abasts només restringeixen; mai no donen més que els permisos de l'usuari.

### Un sol resolutor de permisos per a kpcrudy i kpapy

> Només per als projectes que també fan servir kpcrudy. Sense kpcrudy, implementa només `Kpapy\PermissionResolver` (vegeu les receptes anteriors).

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
// app/Config/Kpcrudy.php i app/Config/Kpapy.php
public ?string $permissionResolver = \App\Libraries\Permissions::class;
public bool $denyByDefault         = true;
```

Fes servir el mateix `setResource('library-books')` al CRUD i al recurs de l'API: un permís concedit val per als dos. Amb els identificadors emmascarats activats a les dues llibreries i la mateixa clau, els id també són idèntics.

### Registres propis

```php
// L'usuari només veu i modifica els seus préstecs
(new Resource())->setTable('loans')->where('user_id', auth('tokens')->id())->render();

// L'usuari veu tots els articles però només modifica els seus
(new Resource())->setTable('posts')
    ->setPermission('edit', fn (?array $row) => $row === null || (int) $row['author_id'] === auth('tokens')->id())
    ->setPermission('delete', fn (?array $row) => $row === null || (int) $row['author_id'] === auth('tokens')->id())
    ->render();
```

Dins d'un recurs, `$api->user()` (o `\Kpapy\Auth\Context::user()`) és l'usuari autenticat.

### Pujada de fitxers i fitxers privats

```php
$api->fieldType('cover', 'image', ['path' => WRITEPATH . 'uploads/covers', 'maxSize' => 1024])          // privat
    ->fieldType('photo', 'image', ['path' => FCPATH . 'uploads/photos', 'url' => base_url('uploads/photos')]); // públic
```

Pujada amb `multipart/form-data`:

```bash
curl -H "Authorization: Bearer $TOKEN" -F cover=@cover.jpg https://example.com/api/v1/books/aB7…/files/cover
```

Un fitxer privat es retorna com una URL signada que caduca (`fileUrlLifetime`), que l'app pot fer servir directament en un component d'imatge.

### Traduccions i Accept-Language

```php
$api->setTranslations('book_translations', 'book_id', 'locale', ['summary'], ['en', 'ca', 'es'], ['required' => ['en']]);
```

L'app envia el seu idioma amb `Accept-Language: ca` (o `?lang=ca`); una traducció que falta es busca a `Config\Kpapy::$localeFallbacks` i a l'idioma per defecte de l'aplicació. Per editar tots els idiomes alhora, llegeix amb `?lang=*` i escriu un objecte: `{"summary": {"en": "…", "ca": "…", "es": "…"}}`.

### CORS per a una aplicació d'una sola pàgina

```php
// app/Config/Kpapy.php
public array $corsOrigins = ['https://app.example.com'];
```

El filtre `kpapy` respon les peticions prèvies i afegeix les capçaleres de CORS per a aquests orígens. L'SPA envia el token a la capçalera `Authorization` (mai galetes), de manera que no hi intervé cap token CSRF.

### Versions: api/v1, api/v2

Posa la versió al camí, i registra un recurs en diverses versions mentre no canviï:

```php
foreach (['api/v1', 'api/v2'] as $prefix) {
    Resource::routes($routes, $prefix . '/books', 'Api\Books::index', ['filter' => 'kpapy']);
}

Resource::routes($routes, 'api/v2/authors', 'Api\V2\Authors::index', ['filter' => 'kpapy']);   // canviat a la v2
```

### Document OpenAPI i pàgina de documentació

```php
\Kpapy\OpenApi\DocsController::routes($routes, 'api/v1/docs', ['filter' => 'session']);   // només personal identificat
```

`/api/v1/docs` és una pàgina llegible i `/api/v1/docs/openapi.json` el document per a Swagger UI, Postman o generadors de codi. Afegeix-hi descripcions amb `->describe('Books', 'Llibres de la biblioteca')` i l'opció `description` de les operacions pròpies. Per generar un fitxer estàtic en el desplegament:

```bash
php spark kpapy:openapi --prefix api/v1 --output public/openapi.json
```

### Límits de peticions

```php
// app/Config/Kpapy.php
public int $rateLimit       = 300;   // per token i minut
public int $rateLimitPublic = 30;    // per IP, rutes sense token
public int $rateLimitLogin  = 5;     // per IP i per compte, inici de sessió de les apps
```

Per sobre del límit l'API respon `429` amb `Retry-After`. Darrere d'un proxy, configura `Config\App::$proxyIPs` perquè es faci servir la IP real.

### Fer tests d'una API

Amb els tests de funcionalitat de CodeIgniter i Shield:

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

    protected $namespace = null;   // totes les migracions (Shield, Kpapy, App)

    public function testReadOnlyToken(): void
    {
        $users = auth()->getProvider();
        $users->save(new User(['username' => 'ana', 'email' => 'ana@example.com', 'password' => 'Secret-123-pass']));
        $user  = $users->findById($users->getInsertID());
        $token = (new TokenService())->create($user, 'test', ['library-books.list'])['token'];

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('api/v1/books')->assertStatus(200);
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')->post('api/v1/books', ['title' => 'Nou'])->assertStatus(403);
    }
}
```

Llegeix el cos amb `json_decode((string) $result->response()->getBody(), true)`. Els tests de la llibreria (a `demo/tests/unit/Kpapy`) són exemples complets, inclòs l'inici de sessió amb el codi al perfil web.
