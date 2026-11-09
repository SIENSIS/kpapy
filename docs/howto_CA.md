# Receptes

[English](howto_EN.md) · **Català**

Solucions breus per a casos habituals. La referència completa és a [functions_CA.md](functions_CA.md).

## Índex

- [Una API pública de només lectura](#una-api-pública-de-només-lectura)
- [Inici de sessió d'una app mòbil amb segon factor](#inici-de-sessió-duna-app-mòbil-amb-segon-factor)
- [Inicis de sessió pendents al perfil web (usuaris sense 2FA)](#inicis-de-sessió-pendents-al-perfil-web-usuaris-sense-2fa)
- [Un proveïdor TOTP que llegeix el secret de l'aplicació](#un-proveïdor-totp-que-llegeix-el-secret-de-laplicació)
- [Un controlador d'inici de sessió propi](#un-controlador-dinici-de-sessió-propi)
- [Tokens personals al perfil web](#tokens-personals-al-perfil-web)
- [Marcar els tokens i veure des d'on es fan servir](#marcar-els-tokens-i-veure-des-don-es-fan-servir)
- [Abasts per a integracions](#abasts-per-a-integracions)
- [Quins tokens poden gestionar tokens (me/tokens)](#quins-tokens-poden-gestionar-tokens-metokens)
- [Un sol resolutor de permisos per a tota l'API](#un-sol-resolutor-de-permisos-per-a-tota-lapi)
- [Registres propis](#registres-propis)
- [Pujada de fitxers i fitxers privats](#pujada-de-fitxers-i-fitxers-privats)
- [Desar els fitxers amb el servei d'emmagatzematge de l'aplicació](#desar-els-fitxers-amb-el-servei-demmagatzematge-de-laplicació)
- [Traduccions i Accept-Language](#traduccions-i-accept-language)
- [CORS per a una aplicació d'una sola pàgina](#cors-per-a-una-aplicació-duna-sola-pàgina)
- [Versions: api/v1, api/v2](#versions-apiv1-apiv2)
- [Document OpenAPI i pàgina de documentació](#document-openapi-i-pàgina-de-documentació)
- [Descriure endpoints propis al document OpenAPI](#descriure-endpoints-propis-al-document-openapi)
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

### Un controlador d'inici de sessió propi

Per canviar un pas de l'inici de sessió de les apps (p. ex. registrar-lo, refusar alguns dispositius o fer servir una configuració pròpia), estén `LoginController` i passa'l a `AuthRoutes::register()`; les rutes no canvien:

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

O per a totes les crides, a `app/Config/Kpapy.php`: `public string $loginController = \App\Controllers\Api\Login::class;`. El cinquè paràmetre (o `$tokenController`) fa el mateix per a `me/tokens` amb una subclasse de `Kpapy\Auth\TokenController`. El document OpenAPI continua mostrant els endpoints d'inici de sessió.

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

### Marcar els tokens i veure des d'on es fan servir

Els tokens personals (creats al perfil web) i els de les apps (creats a l'inici de sessió de les apps) són tots dos tokens d'accés de Shield. Marca els personals amb l'opció `kind`, perquè la pàgina del perfil només mostri aquests i la resta del codi els pugui distingir (p. ex. per conservar-los quan l'usuari canvia la contrasenya):

```php
use Kpapy\Auth\Context;
use Kpapy\Auth\TokenService;

$service = new TokenService();
$token   = $service->create(auth()->user(), 'ERP', ['library-books.list'], 90 * DAY, ['kind' => 'personal']);

$personal = $service->list(auth()->user(), 'personal');            // només els tokens personals
$isPersonal = TokenService::kindOf(Context::token()) === 'personal'; // dins d'una petició de l'API
```

La marca es desa a la columna `secret2` de la taula `auth_identities` de Shield (buida a tots els altres tokens d'accés), i per tant també funciona una consulta a la taula: `->where('type', 'access_token')->where('secret2', 'personal')`.

Per mostrar a l'usuari des d'on s'ha fet servir cada token per darrer cop, activa la darrera adreça IP i executa la migració de la biblioteca:

```php
// app/Config/Kpapy.php
public bool $trackTokenIps = true;
```

```bash
php spark migrate -n Kpapy      # crea kpapy_token_usage
```

A partir d'aleshores, `$service->list($user)` i `GET me/tokens` retornen `last_ip` per a cada token que es faci servir:

```json
{"data": [{"id": 12, "name": "ERP", "scopes": ["library-books.list"], "last_used_at": "2026-11-09T10:02:11+00:00", "kind": "personal", "last_ip": "203.0.113.7", "current": false}]}
```

Per desar l'historial en un altre lloc (el registre d'activitat, una llista d'adreces per token…), escolta l'esdeveniment, que es dispara a cada petició autenticada amb un token tant si `trackTokenIps` està activat com si no:

```php
// app/Config/Events.php
Events::on('kpapy.token.used', static function (array $data): void {
    // $data: user, token, token_id, ip, user_agent
    model(\App\Models\TokenIpModel::class)->remember($data['token_id'], $data['ip'], $data['user_agent']);
});
```

Darrere d'un proxy, configura `Config\App::$proxyIPs` perquè `ip` sigui l'adreça del client.

### Abasts per a integracions

Un token per a un ERP que només llegeix llibres i autors:

```php
(new TokenService())->create($user, 'ERP', ['library-books.list', 'library-books.show', 'library-authors.*']);
```

Encara que l'usuari sigui administrador, aquest token rep `403` a qualsevol escriptura. Els abasts només restringeixen; mai no donen més que els permisos de l'usuari.

### Quins tokens poden gestionar tokens (me/tokens)

`GET me/tokens` i `DELETE me/tokens/{id}` permeten a un client llistar i revocar els tokens del seu usuari. Des de la 1.1, un token amb abasts limitats necessita l'abast `tokens.manage` per fer-los servir: així un token filtrat d'una integració no pot revocar els altres tokens de l'usuari ni saber-ne els noms:

```php
$service = new TokenService();

$service->create($user, 'ERP', ['library-books.list']);                     // GET me/tokens → 403
$service->create($user, 'Admin tool', ['library-books.*', 'tokens.manage']); // pot llistar i revocar
$service->create($user, 'Phone', ['*']);                                    // il·limitat: també pot
```

L'inici de sessió de les apps crea els tokens amb `defaultScopes` (`['*']` per defecte), de manera que les apps continuen funcionant. Una app que demana abasts limitats en iniciar la sessió hi afegeix l'abast: `{"email": …, "scopes": ["library-books.list", "tokens.manage"]}`.

Per canviar el nom de l'abast o tornar al comportament de la 1.0 (qualsevol token de l'usuari), a `app/Config/Kpapy.php`:

```php
public string $tokensScope = 'account.tokens';    // també accepta account.*
public bool $tokensScopeRequired = false;         // comportament de la 1.0: no recomanat
```

### Un sol resolutor de permisos per a tota l'API

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

Cada recurs anomena els seus permisos amb `setResource('library-books')`: el permís de Shield `library-books.edit` (o el comodí `library-books.*`) deixa editar llibres a través de l'API. `php spark kpapy:routes` llista els permisos que demana cada recurs.

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

### Desar els fitxers amb el servei d'emmagatzematge de l'aplicació

Si l'aplicació ja té un servei d'emmagatzematge (disc privat, noms aleatoris, llistes de tipus permesos, S3…), fes que kpapy li passi els fitxers en lloc d'escriure'ls a `writable/uploads/kpapy`. Escriu un gestor de fitxers:

```php
namespace App\Storage;

use CodeIgniter\HTTP\Files\UploadedFile;
use Kpapy\Storage\UploadHandler;
use Kpapy\Storage\UploadReader;

/**
 * Camps de fitxer de l'API a través de service('storage').
 * Opcions del camp: 'disk' ('private' per defecte) i 'folder'.
 */
class ApiStorageHandler implements UploadHandler, UploadReader
{
    public function store(UploadedFile $file, string $field, array $context): string
    {
        $disk   = $context['options']['disk'] ?? 'private';
        $folder = $context['options']['folder'] ?? $context['resource'] . '/' . $field;

        // El servei decideix el nom i el lloc; kpapy desa a la columna el que retorna
        return service('storage')->store($file, $disk, $folder);
    }

    public function delete(string $stored, string $field, array $context): void
    {
        service('storage')->delete($stored);            // no ha de fallar si ja no hi és
    }

    public function url(string $stored, string $field, array $context): ?string
    {
        // Fitxers públics: la seva URL. Privats: null, kpapy dona una URL signada i temporal
        return ($context['options']['disk'] ?? 'private') === 'public' ? service('storage')->url($stored) : null;
    }

    public function read(string $stored, string $field, array $context): ?string
    {
        return service('storage')->read($stored);       // contingut d'un fitxer privat, o null
    }
}
```

Fes-lo servir per a tots els recursos o per a un sol camp:

```php
// app/Config/Kpapy.php
public string $uploadHandler = \App\Storage\ApiStorageHandler::class;
```

```php
$api->fieldType('contract', 'upload', ['allowed' => ['pdf'], 'maxSize' => 4096])                // privat (URL signades)
    ->fieldType('photo', 'image', ['handler' => new ApiStorageHandler(), 'disk' => 'public']);  // URL pública
```

Com funciona:

- **Validació.** kpapy continua comprovant l'extensió, el tipus real, les imatges i `maxSize` abans de cridar `store()`. Si el servei té regles pròpies, implementa també `Kpapy\Storage\UploadValidator` i retorna el missatge d'error des de `validate()`: aleshores kpapy només crida la teva.
- **Fitxers privats.** `url()` retorna `null` i, com que el gestor implementa `UploadReader`, l'API dona una URL signada cap a `GET /api/v1/<recurs>/{id}/files/<camp>?expires=…&signature=…`, que kpapy serveix amb `read()` després de comprovar la signatura i que el registre continua sent visible. Sense `UploadReader`, un camp privat surt com a `null` (dona'ls una URL pròpia amb `callbackField()`).
- **Esborrat.** kpapy crida `delete()` quan se substitueix un fitxer, quan s'esborra el registre definitivament (no quan va a la paperera; `deleteFiles => false` els conserva) i quan falla el desament del registre després d'haver desat el fitxer.
- **Context.** `$context` té `resource`, `table`, `id` (`null` en una alta), `record`, `options` (les opcions del camp, també les claus pròpies com `disk`), `type`, `config` i `api`; fes-lo servir per triar carpetes per recurs, per propietari (`$context['record']['user_id']`) o per client.

La mateixa classe funciona amb kpcrudy (la mateixa interfície): el back office i l'API desen els fitxers al mateix lloc.

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

`/api/v1/docs` és una pàgina llegible i `/api/v1/docs/openapi.json` el document per a Swagger UI, Postman o generadors de codi. Cada endpoint de la pàgina té **Prova-ho** (fora de producció): omple els paràmetres, ajusta el cos JSON proposat i envia la petició amb un token enganxat al quadre de dalt o després d'iniciar-hi la sessió com una app; **Copy as curl** dona la mateixa petició per a un terminal. Per oferir-ho en producció només a algunes persones, protegeix la ruta i activa-ho:

```php
// app/Config/Kpapy.php
public bool $docsInProduction = true;
public ?bool $docsTryIt = true;
```

```php
\Kpapy\OpenApi\DocsController::routes($routes, 'api/v1/docs', ['filter' => 'group:admin']);
```

Afegeix-hi descripcions amb `->describe('Books', 'Llibres de la biblioteca')` i l'opció `description` de les operacions pròpies. Per generar un fitxer estàtic en el desplegament:

```bash
php spark kpapy:openapi --prefix api/v1 --output public/openapi.json
```

### Descriure endpoints propis al document OpenAPI

Un controlador normal com `GET api/v1/me` no és un recurs, i el generador no sap què retorna. Descriu-lo amb l'opció de ruta `openapi` (un Operation Object d'OpenAPI):

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

El camí, el mètode i la seguretat surten de la ruta (les rutes `kpapy:public` o `kpapy:optional` es marquen com a rutes que no necessiten token); els paràmetres del camí com `(:num)` s'hi afegeixen com a `{p1}`; sense `responses`, s'hi afegeix un `200 OK`. La pàgina de documentació el mostra sota la seva etiqueta, amb **Prova-ho**, i `php spark kpapy:openapi` l'inclou.

Si la ruta es declara en un lloc on no pots afegir l'opció (un altre paquet, un mòdul), registra la descripció des del teu codi, amb el camí complet:

```php
use Kpapy\OpenApi\Generator;

Generator::addPath('api/v1/me', 'get', ['tags' => ['Account'], 'summary' => 'Current user']);
```

Crida-ho des d'un lloc que s'executi abans de generar el document, p. ex. just després de la ruta a `app/Config/Routes.php`. Els camins es filtren amb `--prefix` / `DocsController::prefix()` com els recursos.

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
