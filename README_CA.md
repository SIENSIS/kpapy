# kpapy

[English](README_EN.md) · **Català**

Generador automàtic d'API REST per a **CodeIgniter 4** (4.4 o posterior, PHP 8.1+), a l'estil de [kpcrudy](https://github.com/SIENSIS/kpcrudy).

> **kpapy és una llibreria independent.** No necessita kpcrudy: té el seu propi resolutor de permisos, emmascarador d'identificadors, validació i configuració, i funciona en qualsevol projecte CodeIgniter 4. kpcrudy és una integració **opcional**: si un projecte fa servir totes dues, poden compartir el resolutor de permisos i els identificadors emmascarats, i així el back office web i l'API es comporten igual.

Descrius un recurs (una taula o un model, els seus camps, relacions, filtres i permisos) en un mètode de controlador, i kpapy en serveix una API JSON segura i documentada. Només cal una ruta i un mètode.

Funcionalitats:

- **Llistat** amb paginació (per pàgines o per **cursor**), **filtres** amb operadors, **cerca**, **ordenació**, **selecció de camps** i **inclusió** de registres relacionats
- **Detall**, **alta** (`POST`), **edició parcial i completa** (`PATCH`, `PUT`), **esborrat**, **paperera**, **restauració** i **esborrat definitiu** (soft deletes de CodeIgniter)
- **Relacions 1-N i N-N**, **camps traduïts** en una taula filla (`?lang=` o `Accept-Language`), camps **multiselecció**, **pujada de fitxers** amb URL signades i temporals per als fitxers privats
- **Operacions pròpies** (`POST /books/{id}/lend`) i **accions massives** (`POST /books/bulk`), amb hooks abans i després de cada escriptura
- Validació amb regles de CodeIgniter (també deduïdes de la taula), errors en format **RFC 9457** `application/problem+json`, traduïts a sis idiomes
- **Permisos centralitzats** (`PermissionResolver`; si el projecte també fa servir kpcrudy, una sola classe pot servir per a totes dues), `setPermission()` per recurs i el catàleg de permisos
- **Identificadors emmascarats** a totes les URL i respostes; si el projecte també fa servir kpcrudy, són **idèntics als de kpcrudy** (un registre té el mateix id al web i a l'API)
- **Autenticació amb tokens d'accés de CodeIgniter Shield**, **abasts** (scopes) del token combinats amb els permisos de l'usuari, tokens HMAC per a integracions entre servidors
- **Inici de sessió per a apps amb segon factor**: el mètode actiu de l'usuari (aplicació d'autenticació, codi per correu) o, per als usuaris sense 2FA, un **codi que apareix al seu perfil web**
- **ETag**, `GET` condicional (`304`) i concurrència optimista amb `If-Match` (`412`)
- **CORS**, **límits de peticions** per token, per IP i per a l'inici de sessió, HTTPS obligatori en producció
- **OpenAPI 3.1** generat a partir dels recursos, i una pàgina de documentació
- Comandes `spark`: generar un recurs a partir d'una taula, el document OpenAPI, llistar les rutes de l'API, crear tokens, publicar la configuració, instal·lar la demo

---

## Documentació

- [docs/functions_CA.md](docs/functions_CA.md): referència de totes les funcions públiques, opcions i paràmetres de consulta.
- [docs/howto_CA.md](docs/howto_CA.md): receptes (API pública, inici de sessió d'apps amb 2FA, tokens personals, compartir permisos amb kpcrudy, fitxers, CORS, tests…).
- [docs/commands_CA.md](docs/commands_CA.md): les comandes `spark`, amb sortides reals.
- [demo/README_CA.md](demo/README_CA.md): l'API de demostració d'una biblioteca, amb una consola web.
- [docs/requirements.md](docs/requirements.md): l'especificació a partir de la qual s'ha fet la llibreria (en anglès).

## Instal·lació

La llibreria està publicada a [Packagist](https://packagist.org/packages/siensis/kpapy). Instal·la-la amb Composer:

```bash
composer require siensis/kpapy:dev-main
```

Encara no hi ha cap versió etiquetada, per això la restricció és `dev-main` (el darrer commit de la branca `main`). Quan n'hi hagi, fes servir `composer require siensis/kpapy` (Composer hi posarà `^1.0` o la versió vigent) i actualitza amb `composer update siensis/kpapy`.

Requisits: PHP 8.1+, CodeIgniter 4.4+. Opcionals:

- [`codeigniter4/shield`](https://shield.codeigniter.com/): autenticació amb tokens, abasts i l'inici de sessió de les apps. Sense Shield pots fer servir recursos públics o escriure el teu propi `Authenticator`.
- [`siensis/kpcrudy`](https://github.com/SIENSIS/kpcrudy): només si també vols CRUD del back office que comparteixin permisos i identificadors emmascarats amb l'API. kpapy no el necessita i Composer no l'instal·la.

CodeIgniter descobreix sol l'espai de noms `Kpapy` (filtre `kpapy`, comandes, idiomes, vistes, migracions). Si fas servir l'inici de sessió de les apps, crea'n la taula:

```bash
php spark migrate -n Kpapy
```

## Inici ràpid

1. Un mètode de controlador que descriu el recurs:

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
            ->setResource('library-books')                          // nom per als permisos
            ->setRelation('author_id', 'authors', ['name'], ['as' => 'author'])
            ->filters(['author_id', 'year'])
            ->searchFields(['title'])
            ->render();
    }
}
```

2. Les rutes (una sola crida registra tots els mètodes i camins del recurs):

```php
// app/Config/Routes.php
\Kpapy\Resource::routes($routes, 'api/v1/books', 'Api\Books::index', ['filter' => 'kpapy']);
```

3. Les rutes de l'API fan servir tokens, no galetes: exclou-les del filtre CSRF de CodeIgniter.

```php
// app/Config/Filters.php
public array $globals = [
    'before' => [
        'csrf' => ['except' => ['api/*']],
    ],
];
```

Els passos 1 i 2 es poden generar amb `php spark kpapy:make books`.

### Què n'obtens

| Petició | Resultat |
|---|---|
| `GET /api/v1/books?filter[year][gte]=1970&sort=-year&include=author&page=2` | Llistat |
| `GET /api/v1/books/{id}` | Detall (amb `ETag`) |
| `POST /api/v1/books` | Alta → `201` + `Location` |
| `PATCH /api/v1/books/{id}` | Edició parcial (`If-Match` opcional) |
| `DELETE /api/v1/books/{id}` | A la paperera (o esborrat), `204` |
| `POST /api/v1/books/{id}/restore` | Restaura de la paperera |
| `POST /api/v1/books/bulk` | Acció massiva |

Cada petició necessita `Authorization: Bearer <token>` (filtre `kpapy`); fes servir `'kpapy:public'` per als recursos sense autenticació.

### Format de les respostes

```json
{
  "data": [ { "id": "Xk3f9QaLm2", "title": "Invisible Cities", "author_id": "aB7…", "year": 1972 } ],
  "meta": { "per_page": 25, "page": 1, "total": 120, "pages": 5 },
  "links": { "self": "…?page=1", "first": "…?page=1", "prev": null, "next": "…?page=2", "last": "…?page=5" }
}
```

Un sol registre és `{"data": {…}, "links": {"self": "…"}}`. Les dates van en ISO 8601 amb zona horària, els decimals com a text i els booleans com a `true`/`false`.

### Errors

```json
{
  "type": "about:blank",
  "title": "Dades no vàlides",
  "status": 422,
  "code": "validation",
  "errors": { "title": ["El camp title és obligatori."] }
}
```

`application/problem+json` (RFC 9457) amb un `code` estable: `bad-request` (400), `unauthorized` (401), `forbidden` (403), `not-found` (404, també per als registres de fora del `where()`), `method-not-allowed` (405), `conflict`/`operation-failed` (409), `precondition-failed` (412), `payload-too-large` (413), `unsupported-media-type` (415), `validation` (422), `precondition-required` (428), `too-many-requests` (429, amb `Retry-After`), `server-error` (500, sense detalls fora de `development`).

## Configuració

`php spark kpapy:publish config` escriu `app/Config/Kpapy.php` amb totes les opcions i el seu comentari; deixa-hi només el que canviïs. Les més habituals:

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
    public int $rateLimit              = 120;      // per token i minut
    public string $profileUrl          = 'https://example.com/perfil';
}
```

Totes les opcions són a [docs/functions_CA.md](docs/functions_CA.md#configuració).

## Demo

La llibreria inclou una petita API de biblioteca (llibres, autors, etiquetes, préstecs) amb usuaris de prova, l'inici de sessió de les apps amb els dos mètodes de segon factor, una consola web per enviar peticions i la pàgina de documentació. S'instal·la en qualsevol projecte amb Shield:

```bash
php spark kpapy:demo install
```

i es treu sense deixar rastre amb `php spark kpapy:demo uninstall`. Vegeu [demo/README_CA.md](demo/README_CA.md).

## Idiomes

Els títols dels errors i els missatges de validació i d'autenticació estan en **català, castellà, anglès, francès, portuguès i alemany** (`src/Language/{codi}/Kpapy.php`) i segueixen el locale de la petició; els idiomes que falten tornen a l'anglès. `php spark kpapy:publish lang ca` copia un fitxer a `app/Language/` per adaptar-lo.

## Notes de seguretat

- Les rutes de l'API no fan servir mai galetes ni sessió: l'autenticació és un token d'accés de Shield (o un token HMAC) a la capçalera `Authorization`, i per això el CSRF no hi té sentit. Exclou-les del filtre `csrf` i no barregis mai l'API amb rutes autenticades per sessió.
- Els permisos es comproven al servidor per a cada operació, registre i operació pròpia; els abasts del token només poden **restringir** el que pot fer l'usuari.
- `where()` limita totes les operacions: un registre de fora és `404`, mai no es revela. Les condicions d'igualtat també **s'imposen en escriure**.
- Del cos de la petició només es llegeixen els camps que es poden escriure (protecció d'assignació massiva); amb `strictInput`, la resta es refusa.
- Els camps sensibles (`password`, `token`, `secret`…) no es retornen mai si no es demanen explícitament; les contrasenyes es desen amb hash.
- Els identificadors emmascarats van xifrats i signats; un codi fals o modificat és `404`.
- Els fitxers privats només se serveixen amb URL signades que caduquen.
- L'inici de sessió de les apps té límit de peticions per IP i per compte, missatges genèrics, reptes d'un sol ús amb intents limitats i sempre un segon factor.
- Els tokens personals només es creen des del perfil web (`TokenService`), mai des de l'API: un token filtrat no en pot crear d'altres.
- En producció es refusen les peticions que no són HTTPS (`requireHttps`), i els errors no inclouen mai detalls interns.
