# Referència de funcions

[English](functions_EN.md) · **Català**

Referència de les classes i funcions públiques de kpapy. Tots els exemples suposen:

```php
use Kpapy\Resource;
```

## Índex

- [Crear i executar](#crear-i-executar): `__construct`, `render`, `routes`, `setBasePath`
- [Origen de dades](#origen-de-dades): `setTable`, `setModel`, `setDatabase`, `setPrimaryKey`, `where`, `softDeletes`
- [Camps](#camps): `fields`, `listFields`, `writeFields`, `addFields`, `editFields`, `hideFields`, `readonlyFields`, `requiredFields`, `setDefault`, `setRules`, `fieldType`, `callbackField`, `transform`
- [Relacions](#relacions): `setRelation`, `setRelationNN`
- [Traduccions](#traduccions): `setTranslations`
- [Llistat](#llistat): `filters`, `searchFields`, `unsortable`, `defaultOrder`, `setPerPage`, `cursorPagination`, `withoutTotal`, paràmetres de consulta
- [Operacions i HTTP](#operacions-i-http): la taula de correspondències, entrada, sortida, ETag
- [Operacions pròpies i accions massives](#operacions-pròpies-i-accions-massives): `addOperation`, `addBulkAction`, `fail`
- [Hooks](#hooks): `beforeInsert`, `afterInsert`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`, `afterRestore`, `afterWrite`
- [Permisos](#permisos): `setPermission`, `unset*`, `readOnly`, `setResource`, `setPermissionResolver`, `isAllowed`, `permissionCatalogue`, `impliedPermissions`, `PermissionResolver`, abasts, registres propis
- [Identificadors emmascarats](#identificadors-emmascarats): `maskIds`, `encodeId`, `decodeId`
- [Fitxers](#fitxers): camps de pujada, URL signades, `UploadHandler` (emmagatzematge propi)
- [Accés des dels callbacks](#accés-des-dels-callbacks): `getTable`, `getPrimaryKey`, `getConfig`, `getRequest`, `user`, `url`, `masksIds`
- [Errors](#errors): `ApiException`
- [El filtre `kpapy`](#el-filtre-kpapy)
- [Autenticació](#autenticació): `Authenticator`, `Context`, `TokenService`, `AuthRoutes`, l'abast `tokens.manage`, inici de sessió de les apps, proveïdors de segon factor, esdeveniments
- [OpenAPI i documentació](#openapi-i-documentació): `describe`, `DocsController`, `Generator`, `Generator::addPath`, l'opció de ruta `openapi`
- [Configuració](#configuració): `Config\Kpapy`

---

## Crear i executar

### `__construct(?\Kpapy\Config\Kpapy $config = null)`

Crea un recurs. Sense paràmetre fa servir `config('Kpapy')` (el teu `app/Config/Kpapy.php` si existeix, o la de la llibreria). Es pot passar una configuració concreta, p. ex. per a un mòdul amb opcions pròpies.

### `render(): ResponseInterface`

Atén la petició: resol el mètode i el camí, comprova els permisos, executa l'operació i retorna la resposta JSON. Cada `Kpapy\Http\ApiException` es converteix en una resposta `problem+json`; qualsevol altra excepció dona un `500` (amb la classe i el missatge només a `development`) i queda al log.

```php
public function index(): ResponseInterface
{
    return (new Resource())->setTable('books')->render();
}
```

### `Resource::routes(RouteCollection $routes, string $path, string|Closure $handler, array $options = []): void` (estàtic)

Registra tots els mètodes (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `OPTIONS`) del recurs per a `$path` i `$path/(:any)` amb un sol controlador. Les opcions són les habituals de les rutes de CodeIgniter; només la ruta base porta el nom `as`, de manera que `url_to()` / `route_to()` funcionen.

```php
Resource::routes($routes, 'api/v1/books', 'Api\Books::index', ['filter' => 'kpapy', 'as' => 'api-books']);

// En un mòdul: el controlador amb el nom complet de la classe
Resource::routes($routes, 'api/v1/posts', '\Modules\Blog\Controllers\Api\Posts::index', ['filter' => 'kpapy']);
```

El camí pot tenir paràmetres (`api/v1/authors/(:num)/books`): el que hi ha després és el camí propi del recurs.

### `setBasePath(string $path): static`

Camí base del recurs quan no es registra amb `Resource::routes()` (p. ex. als tests o amb rutes pròpies). Per defecte, la ruta que ha atès la petició.

---

## Origen de dades

### `setTable(string $table): static`

Treballa directament amb una taula. La clau primària, els camps `created_at` / `updated_at` i la paperera (`deleted_at`) es detecten de la seva estructura.

### `setModel(Model|string $model): static`

Treballa amb un model de CodeIgniter: la seva taula, clau primària, `allowedFields` (camps que es poden escriure), regles i missatges de validació, timestamps, paperera i callbacks (les altes i edicions passen pel model).

### `setDatabase(BaseConnection|string $db): static` · `setPrimaryKey(string $key): static`

Grup o connexió de base de dades, i la clau primària quan no es pot detectar.

### `where(array|Closure|string $field, mixed $value = null): static`

Limita **totes** les operacions: llistat, detall, escriptures, esborrat, operacions pròpies i accions massives. Un registre de fora és `404`. Les condicions d'igualtat també **s'imposen en escriure**: el client no pot enviar el camp i sempre es desa amb aquest valor.

```php
$api->where('user_id', auth()->id());          // registres propis
$api->where(['status' => 'published']);
$api->where('year >=', 2000);                  // només filtra (no s'imposa en escriure)
```

*Des de 1.2.* Una **closure** `fn (BaseBuilder $builder, Resource $api)` afegeix condicions pròpies (`OR`, subconsultes, altres taules amb `whereIn()` i una subconsulta…) a totes les consultes del recurs, entre parèntesis perquè un `OR` no pugui ampliar l'abast. Els valors passen pel Query Builder (vinculats i escapats); no s'imposa res en escriure. Una closure que no afegeix cap condició s'ignora.

```php
// Visibles per a l'usuari: públiques, o compartides amb un dels seus grups
$api->where(static fn (BaseBuilder $builder) => $builder
    ->where('posts.visibility', 'public')
    ->orWhereIn('posts.id', static fn (BaseBuilder $sub) => $sub->select('post_id')->from('post_groups')->whereIn('group_id', $groupIds)));
```

### `softDeletes(bool $enabled = true, string $field = 'deleted_at'): static`

Activa o desactiva la paperera en mode taula (en mode model ho decideix el model). Amb paperera, `DELETE` hi envia el registre, `?trashed=` el mostra, `POST /{id}/restore` el restaura i `DELETE /{id}?purge=1` l'esborra definitivament.

---

## Camps

### `fields(array $fields): static`

Camps que es retornen al detall (i al llistat, si no hi ha `listFields()`). Poden incloure camps virtuals calculats amb `callbackField()`, relacions N-N i camps traduïts. La clau primària sempre hi és. Per defecte: totes les columnes excepte la de la paperera i les sensibles, més els camps traduïts i les relacions N-N.

### `listFields(array $fields): static`

Camps del llistat quan són diferents dels del detall (p. ex. sense textos llargs). El client pot demanar qualsevol camp del detall amb `?fields=`.

### `writeFields(array $fields): static` · `addFields(array $fields): static` · `editFields(array $fields): static`

Camps que el client pot enviar, per a les dues operacions o per separat per a l'alta i l'edició. Per defecte: els `allowedFields` del model (mode model) o totes les columnes excepte la clau primària, els timestamps, la de la paperera i les sensibles (les contrasenyes sí), més els camps traduïts i les relacions N-N. **La resta del cos s'ignora** (protecció d'assignació massiva), o es refusa amb `422` si `Config\Kpapy::$strictInput` és `true`.

### `hideFields(array $fields): static`

Treu camps de tot arreu: sortida, `?fields=`, filtres, cerca, ordenació, includes i entrada (no es desen mai). Té prioritat sobre les altres llistes, de manera que es pot cridar al final segons un permís:

```php
if (! auth()->user()->can('staff.salary')) {
    $api->hideFields(['salary', 'bank_account']);
}
```

### `readonlyFields(array $fields): static`

Camps que es poden enviar a l'alta però no a l'edició.

### `requiredFields(array $fields): static` · `setDefault(string $field, mixed $value): static`

Camps obligatoris (també traduïts) i el valor que es fa servir a l'alta si el client no envia el camp (`$value` pot ser una closure).

### `setRules(array|string $field, array|string|null $rules = null, array $messages = []): static`

Regles de validació de CodeIgniter. `{id}` se substitueix per l'id real a l'edició. Sense regles, es **dedueixen** de la taula (`inferRules`): `NOT NULL` sense valor per defecte → `required`; `VARCHAR(n)` → `max_length[n]`; enter → `integer`; decimal → `decimal`; real → `numeric`; un camp `email` → `valid_email`; `url`/`*_url`/`website` → `valid_url_strict[http,https]`.

```php
$api->setRules('isbn', 'permit_empty|max_length[20]|is_unique[books.isbn,id,{id}]', ['is_unique' => 'Aquest ISBN ja existeix.']);
```

La validació s'aplica a tots els camps a l'alta i amb `PUT`, i només als camps enviats amb `PATCH`.

### `fieldType(string $field, string $type, array $options = []): static`

Força el tipus d'un camp (si no, es dedueix de la base de dades i del nom):

| Tipus | Sortida | Entrada | Opcions |
|---|---|---|---|
| `int`, `float` | número | número | |
| `decimal` | text (`"12.50"`) | número o text numèric | `scale` (decimals de la sortida) |
| `bool` | `true` / `false` | `true`, `false`, `1`, `0`, `"true"`, `"false"` | (`TINYINT(1)` es detecta) |
| `date` | `"2026-11-03"` | data ISO 8601 | |
| `datetime` | ISO 8601 amb zona horària | ISO 8601 (es converteix a la zona de l'aplicació) | |
| `json` | JSON descodificat | qualsevol valor JSON | |
| `enum` / `select` | text | una de les claus de les opcions | `options` => `[valor => etiqueta]` |
| `multiselect` | llista de textos | llista (o text JSON/CSV) de claus | `options`, `storage` => `json` (per defecte) o `csv` |
| `password` | no es retorna mai | text, desat amb `password_hash()`; buit a l'edició = no canvia | `hash` (per defecte `true`) |
| `upload`, `image` | URL | fitxer multipart | vegeu [Fitxers](#fitxers) |
| `virtual` | de `callbackField()` | — | |

### `callbackField(string $field, Closure $callback): static`

Valor de sortida d'un camp, o d'un de virtual: `fn (mixed $value, array $row): mixed`.

```php
$api->fields(['id', 'name', 'books'])
    ->callbackField('books', fn ($value, array $row) => model(BookModel::class)->where('author_id', $row['id'])->countAllResults());
```

### `transform(Closure $callback): static`

Transforma cada element de sortida: `fn (array $item, array $row): array` (`$row` és la fila de la base de dades).

---

## Relacions

### `setRelation(string $field, string $table, array|string $columns, array $options = []): static`

Relació 1-N (`books.author_id → authors.id`). El camp es retorna com l'id relacionat (emmascarat amb la taula relacionada com a context); amb `?include=<as>` s'hi afegeix el registre relacionat com un objecte amb les columnes indicades. A l'entrada, l'id ha d'existir (i complir `where`).

Opcions: `as` (nom per a `?include=`; per defecte el camp sense `_id`), `pk` (per defecte `id`), `where` (registres permesos).

```php
$api->setRelation('author_id', 'authors', ['name', 'country'], ['as' => 'author']);
// GET /books/{id}?include=author → "author": {"id": "…", "name": "…", "country": "…"}
```

### `setRelationNN(string $name, string $junction, string $otherTable, string $thisKey, string $otherKey, array|string $columns, array $options = []): static`

Relació N-N amb una taula intermèdia. `$name` és un camp virtual que es retorna com una llista d'id, o d'objectes amb `?include=<name>`; s'escriu enviant la llista d'id (les files de la taula intermèdia se substitueixen en una transacció). Es pot filtrar: `?filter[tags]=3,5` (registres amb algun d'ells).

```php
$api->setRelationNN('tags', 'book_tag', 'tags', 'book_id', 'tag_id', ['name']);
```

---

## Traduccions

### `setTranslations(string $table, string $foreignKey, string $localeField, array $fields, array $locales, array $options = []): static`

Camps traduïts en una taula filla, una fila per idioma. Opcions: `required` (idiomes que han de tenir els camps; per defecte, el primer).

```php
$api->setTranslations('book_translations', 'book_id', 'locale', ['summary'], ['en', 'ca', 'es'], ['required' => ['en']]);
```

- **Sortida** en l'idioma demanat: `?lang=ca`; si no, la capçalera `Accept-Language` (també `ca-ES` → `ca`); si no, el primer idioma de la cadena. Un valor que falta es busca seguint la cadena: idioma demanat → `Config\Kpapy::$localeFallbacks` → l'idioma per defecte de l'aplicació → la resta d'idiomes. `?lang=*` retorna tots els idiomes: `"summary": {"en": "…", "ca": "…", "es": null}`.
- **Entrada**: un text (per a l'idioma de la petició) o un objecte per idioma: `"summary": {"en": "…", "ca": "…"}`. Els idiomes obligatoris han de tenir valor a l'alta (si la columna és `NOT NULL` sense valor per defecte, o el camp és obligatori) i no es poden buidar; un idioma opcional enviat buit s'esborra.
- Els camps traduïts de l'idioma de la petició es poden cercar (`q`), filtrar i ordenar.

---

## Llistat

### Paràmetres de consulta

| Paràmetre | Exemple | Notes |
|---|---|---|
| `page`, `per_page` | `?page=2&per_page=50` | `per_page` ≤ `maxPerPage` |
| `cursor` | `?cursor=WyJ…` | Només amb `cursorPagination()`; el següent és a `meta.next_cursor` |
| `sort` | `?sort=-year,title` | `-` = descendent; només camps ordenables |
| `q` | `?q=war` | Cerca a `searchFields()` (permís `search`) |
| `filter[camp]` | `?filter[author_id]=aB7…` | Igualtat (permís `filters`) |
| `filter[camp][op]` | `?filter[year][gte]=1970` | Operadors a continuació |
| `fields` | `?fields=title,year` | Selecció de camps (qualsevol camp del detall) |
| `include` | `?include=author,tags` | Només les relacions declarades |
| `lang` | `?lang=ca`, `?lang=*` | Camps traduïts |
| `trashed` | `?trashed=with`, `?trashed=only` | Amb paperera; permís `trash` |

Operadors dels filtres: `eq`, `ne`, `lt`, `lte`, `gt`, `gte`, `in` (`?filter[year][in]=1962,1974`), `between` (`?filter[price][between]=10,20`), `like` (`%` i `_` es busquen literalment), `null`, `notnull`. Els valors es converteixen i es validen segons el tipus del camp (els id de les relacions es desemmascaren, les dates admeten ISO 8601, els booleans `true`/`false`/`1`/`0`); un camp, operador o valor no vàlid és `400`. Camps multiselecció: `?filter[audience]=teens` (registres que el contenen). Relacions N-N: `?filter[tags]=id1,id2` (registres amb algun d'ells).

### `filters(array $fields): static`

Camps que es poden filtrar. Per defecte: relacions 1-N, `enum`/`select`, booleans i multiselecció.

### `searchFields(array $fields): static`

Camps on cerca `?q=`. Per defecte: les columnes de text (i els camps traduïts) de la sortida que no són relacions, sensibles ni calculades amb `callbackField()`.

### `unsortable(array $fields): static` · `defaultOrder(string $field, string $direction = 'asc'): static`

Camps que no es poden ordenar, i l'ordre quan el client no envia `sort`. La clau primària s'hi afegeix sempre al final perquè les pàgines siguin estables.

### `setPerPage(int $perPage): static`

Registres per pàgina d'aquest recurs (per defecte `Config\Kpapy::$perPage`).

### `cursorPagination(string $field, string $direction = 'desc'): static`

Paginació per cursor en lloc de per pàgines, per a taules grans i desplaçament infinit: `meta.next_cursor` i `links.next` donen la pàgina següent (`null` al final). El cursor porta el valor del camp i l'id (emmascarat), de manera que el camp no cal que sigui únic.

### `withoutTotal(): static`

No compta els registres (taules molt grans): no surten `meta.total`, `meta.pages` ni `links.last`, i `links.next` hi és mentre una pàgina és plena.

---

## Operacions i HTTP

| Operació | Mètode i camí | Èxit | Permís |
|---|---|---|---|
| list | `GET /r` | `200` | `list` |
| show | `GET /r/{id}` | `200` (`304` amb `If-None-Match`) | `show` |
| add | `POST /r` | `201` + `Location` | `add` |
| edit | `PATCH /r/{id}` (parcial), `PUT /r/{id}` (completa) | `200` | `edit` (també amb el registre tal com quedaria) |
| delete | `DELETE /r/{id}` | `204` | `delete` |
| restore | `POST /r/{id}/restore` | `200` | `restore` |
| purge | `DELETE /r/{id}?purge=1` | `204` | `purge` |
| operació pròpia | `POST /r/{id}/{clau}`, `POST /r/{clau}` (o `GET`) | `200`/`201`/`204` | el seu `permission` |
| massiva | `POST /r/bulk` | `200` | `bulk` + per registre |
| pujada | `POST /r/{id}/files/{camp}` | `200` | `edit` |
| descàrrega | `GET /r/{id}/files/{camp}?expires=…&signature=…` | el fitxer | URL signada |
| preflight | `OPTIONS` | `204` + `Allow` | — |

Un mètode que no està disponible respon `405` amb `Allow`. `bulk`, `restore` i `files` són paraules reservades.

**Cos de la petició.** JSON (`Content-Type: application/json`, un objecte) o `multipart/form-data` (necessari per pujar fitxers). Més gran que `maxBodySize` → `413`; JSON no vàlid → `400`; un altre tipus de contingut → `415`.

**Sortida.** Vegeu el [format de les respostes](../README_CA.md#format-de-les-respostes): dates en ISO 8601 amb zona horària, decimals com a text, id de les relacions emmascarats.

**ETag.** Cada registre té un `ETag` (un hash de la fila); els llistats, el hash del cos. `If-None-Match` → `304`. `PATCH`, `PUT` i `DELETE` amb `If-Match` responen `412` si el registre ha canviat (`*` coincideix amb qualsevol). Amb `Config\Kpapy::$requireIfMatch = true`, les escriptures sense `If-Match` responen `428`.

---

## Operacions pròpies i accions massives

### `addOperation(string $key, Closure $callback, array $options = []): static`

Una operació servida des de la mateixa ruta del recurs: `POST /r/{id}/{clau}` (sobre un registre) o `POST /r/{clau}` (`'row' => false`). kpapy comprova el mètode, l'id emmascarat, el `where()`, el permís i `visible` abans de cridar-la.

`fn (?string $id, ?array $row, Resource $api): mixed`, que retorna:

| Valor | Resposta |
|---|---|
| `array` | `{"data": …}` amb `status` (per defecte `200`) |
| `string` | `{"data": {"message": …}}` |
| `null` o `true` | `204` |
| `false` | `409` `operation-failed` |
| `ResponseInterface` | tal qual |

Opcions: `row` (per defecte `true`), `method` (`post` o `get`), `permission` (per defecte, la clau), `visible` (`fn (?array $row): bool`, també bloqueja l'operació), `trash` (sobre registres de la paperera), `status`, `description` (OpenAPI). La clau ha de començar amb una minúscula, només pot tenir minúscules, números, `-` i `_`, i no pot ser una paraula reservada ni una operació pròpia del recurs.

```php
$api->addOperation('lend', function (string $id, array $book, Resource $api) {
    if (! $book['available']) {
        $api->fail('El llibre ja està en préstec.');
    }
    // …
    return ['due_at' => date('Y-m-d', strtotime('+21 days'))];
}, ['status' => 201, 'description' => 'Agafa el llibre en préstec']);
```

### `fail(string $message, int $status = 409, string $type = 'operation-failed'): never`

Atura l'operació (o un hook) amb un error per al client.

### `addBulkAction(string $key, Closure $callback, array $options = []): static`

`POST /r/bulk` amb `{"action": "<clau>", "ids": ["…", "…"]}` (1000 id com a màxim). La closure rep els id reals ja filtrats pel `where()` i pel permís de cada registre: `fn (array $ids, Resource $api): mixed` (un text es retorna com a `message`). Opcions: `permission`, `description`. Les accions `delete`, `restore` i `purge` sempre hi són (amb els seus permisos). Resposta:

```json
{"data": {"processed": ["aB7…"], "skipped": [{"id": "x", "reason": "not-found"}], "message": "1 llibres actualitzats."}}
```

`reason` és `not-found` o `forbidden`.

---

## Hooks


| Hook | Signatura | Retorn |
|---|---|---|
| `beforeInsert` | `fn (array $data): mixed` | array (dades noves), text (`422` amb el missatge), `false` (`409`) o `null` |
| `beforeUpdate` | `fn (array $data, string $id, array $old): mixed` | igual |
| `afterInsert` | `fn (string $id, array $data): void` | |
| `afterUpdate` | `fn (string $id, array $data, array $old): void` | |
| `beforeDelete` | `fn (string $id, array $row, bool $purge): mixed` | un text o `false` cancel·len (`409`) |
| `afterDelete` | `fn (string $id, array $row, bool $purge): void` | |
| `afterRestore` | `fn (string $id, array $row): void` | |
| `afterWrite` | `fn (string $operation, string $id, array $data, ?array $old): void` | després de `insert`, `update`, `delete` i `restore` |

```php
$api->beforeInsert(fn (array $data) => $data + ['created_by' => auth()->id()])
    ->afterWrite(fn (string $op, string $id) => cache()->delete('books'));
```

---

## Permisos

Operacions: `list`, `show`, `add`, `edit`, `delete`, `trash`, `restore`, `purge`, `search`, `filters`, `bulk`, més el `permission` de les operacions pròpies i de les accions massives.

**Com es pren cada decisió** (per a cada operació i registre):

1. Sense paperera, `trash` i `restore` es deneguen; `restore` i `purge` també necessiten `trash`.
2. Els **abasts del token** (si la petició porta token i `checkScopes` està actiu): l'operació hi ha de ser.
3. El **resolutor de permisos** respon `true`, `false` o `null`. `false` denega: el resolutor té l'última paraula per denegar.
4. Una regla `setPermission()` del recurs decideix.
5. Si no, la resposta del resolutor; i si no opinava, `Config\Kpapy::$denyByDefault` (`false` permet, `true` denega).

### `setPermission(string $operation, bool|Closure $allowed): static`

```php
$api->setPermission('delete', false)
    ->setPermission('edit', fn (?array $row) => $row === null || (int) $row['owner_id'] === auth()->id());
```

`$row` és `null` quan la pregunta és general (p. ex. si `POST` està disponible).

### `unsetList()`, `unsetShow()`, `unsetAdd()`, `unsetEdit()`, `unsetDelete()`, `unsetTrash()`, `unsetPurge()`, `unsetBulk()` · `readOnly(): static`

Dreceres de `setPermission(..., false)`. `readOnly()` desactiva `add`, `edit`, `delete`, `restore`, `purge` i `bulk`.

### `setResource(string $resource): static` · `getResource(): string`

Nom del recurs per al resolutor i els abasts (per defecte, la taula). Amb CodeIgniter Shield, `<mòdul>-<recurs>` (`library-books`) dona permisos com `library-books.edit` i permet que `library-books.*` ho concedeixi tot (Shield només expandeix la primera part d'un comodí).

### Interfície `Kpapy\PermissionResolver`

```php
interface PermissionResolver
{
    public function allows(string $resource, string $operation, ?array $row, Resource $api): ?bool;
}
```

Es configura per a tots els recursos a `Config\Kpapy::$permissionResolver`, o per a un amb `setPermissionResolver()`. Exemple amb Shield (`search`, `filters` i `bulk` com a `list`):

```php
class Permissions implements \Kpapy\PermissionResolver
{
    public function allows(string $resource, string $operation, ?array $row, Resource $api): ?bool
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

Una sola classe decideix els permisos de tota l'API. Les respostes sense registre es desen per a cada instància del recurs.

### `setPermissionResolver(PermissionResolver|Closure|null $resolver): static`

Resolutor només per a aquest recurs: una instància, una closure amb la mateixa signatura o `null` per ignorar el de la configuració.

### `isAllowed(string $operation, ?array $row = null): bool`

Si el client pot fer una operació (combinant abasts, resolutor, regles i `denyByDefault`), p. ex. dins de `callbackField()`.

### `permissionCatalogue(): array`

Totes les operacions per les quals pot preguntar aquest recurs: les pròpies que estan actives (`trash`, `restore` i `purge` només amb paperera; sense les desactivades amb `setPermission(..., false)`), les operacions pròpies i els permisos de les accions massives. Serveix per generar o comprovar un catàleg de permisos (vegeu `php spark kpapy:routes`).

### `Resource::impliedPermissions(array $operations): array` (estàtic) · `Resource::IMPLIED_PERMISSIONS`

Completa una llista amb les operacions que pressuposa: `add`, `delete`, `trash`, `search`, `filters`, `bulk` → `list`; `edit` → `list`, `show`; `restore`, `purge` → `trash`.

### Abasts (scopes)

Els tokens es poden limitar a algunes operacions (abasts dels tokens de Shield): `*`, `recurs.*` o `recurs.operació` (`search`, `filters` i `bulk` també s'accepten amb `recurs.list`). Un token pot fer la **intersecció** dels seus abasts i els permisos de l'usuari. Les peticions sense token (rutes públiques) només depenen dels permisos.

### Registres propis

No hi ha cap permís especial:

- l'usuari només ha de **veure** els seus registres → `where('user_id', $id)`;
- l'usuari ho veu tot però només **modifica** els seus → una closure `setPermission('edit', fn (?array $row) => …)` o el resolutor, que reben el registre.

---

## Identificadors emmascarats

### `maskIds(bool $enabled = true, ?string $context = null, IdMasker|string|null $masker = null): static`

Emmascara els identificadors d'aquest recurs (`Config\Kpapy::$maskIds` ho fa per a tots): la clau primària a les URL i les respostes, els id de les relacions (amb la taula relacionada com a context), els cursors i els id de les accions massives. Un codi fals o modificat és `404`.

Algorisme: AES-256-CTR amb un HMAC de l'id com a vector inicial, de manera que un registre té sempre el mateix codi en un context (el nom de la taula) i el codi d'una taula no serveix per a una altra. Clau: `Config\Kpapy::$maskKey` (32 caràcters o més); si no, la clau d'encriptació de CodeIgniter; si no, `writable/kpapy/mask.key` (es crea la primera vegada; per evitar-lo, configura una de les altres dues). Els codis són **estables**: amb la mateixa clau i context no canvien entre versions, i qualsevol altre component que apliqui el mateix algorisme amb la mateixa clau obté els mateixos codis. Per fer servir un altre algorisme, implementa `Kpapy\IdMasker` i indica'l a `Config\Kpapy::$idMasker`.

### `encodeId(int|string $id, ?string $context = null): string` · `decodeId(string $value, ?string $context = null): ?string`

El codi d'un id i a l'inrevés (el context per defecte és la taula).

---

## Fitxers

`fieldType($camp, 'upload'|'image', $opcions)` amb `path` (directori; per defecte `Config\Kpapy::$uploadPath/<taula>`), `url` (URL pública base; sense, els fitxers són **privats**), `allowed` (extensions), `maxSize` (KB), `subdir` (text amb `{camp}` o `fn (array $data): string`), `deleteFiles` (per defecte `true`), `handler` (nom de classe o objecte que implementi `Kpapy\Storage\UploadHandler`; per defecte `Config\Kpapy::$uploadHandler`). `path`, `url` i `subdir` són opcions del gestor per defecte; un gestor propi rep totes les opcions del camp a `$context['options']`, i per tant en pot definir de pròpies (p. ex. `'disk' => 'private'`).

- **Pujada**: al cos `multipart/form-data` de `POST`/`PATCH`, o `POST /r/{id}/files/{camp}` (camp `<camp>` o `file`). Es comproven l'extensió (la declarada i la real), el tipus MIME (imatges) i la mida; el fitxer rep un nom aleatori. Un fitxer substituït s'esborra.
- **Sortida**: la URL pública, o per als fitxers privats una **URL signada** a `GET /r/{id}/files/{camp}?expires=…&signature=…`, vàlida `fileUrlLifetime` segons (la mateixa URL durant un minut, perquè els clients la puguin desar en memòria cau). El registre ha de continuar sent visible.

### Gestors de fitxers: `Kpapy\Storage\UploadHandler`

On i com es desen els fitxers ho decideix un **gestor de fitxers**. El per defecte, `Kpapy\Storage\LocalUploadHandler`, fa exactament el que feia kpapy 1.0 (un directori per camp, noms aleatoris, les opcions `path`, `subdir` i `url`). Per desar els fitxers en un altre lloc (el servei d'emmagatzematge de l'aplicació, S3, una base de dades…), implementa la interfície i configura-la per a tots els recursos a `Config\Kpapy::$uploadHandler` o per a un camp amb l'opció `handler`:

```php
namespace Kpapy\Storage;

interface UploadHandler
{
    // Desa el fitxer (ja validat) i retorna el valor que es guarda a la columna del camp
    public function store(\CodeIgniter\HTTP\Files\UploadedFile $file, string $field, array $context): string;

    // Esborra un fitxer desat (fitxer substituït, registre esborrat, error en desar). No ha de fallar si ja no existeix
    public function delete(string $stored, string $field, array $context): void;

    // URL pública del fitxer, o null si no en té (fitxer privat)
    public function url(string $stored, string $field, array $context): ?string;
}
```

`$context` conté:

| Clau | Tipus | Valor |
|---|---|---|
| `resource` | `string` | Nom del recurs (`setResource()`) |
| `table` | `string` | Taula del recurs |
| `id` | `string\|null` | Clau primària del registre (real, no emmascarada); `null` a `store()` en una alta (el registre encara no existeix) |
| `record` | `array` | `store()`: els valors antics amb els nous; `delete()` / `url()`: la fila desada |
| `options` | `array` | Les opcions del camp a `fieldType()` |
| `type` | `string` | `upload` o `image` |
| `config` | `Kpapy\Config\Kpapy` | Configuració del recurs |
| `api` | `Kpapy\Resource` | El recurs (`getResource()`, `user()`, `url()`…) |

Dues interfícies opcionals hi afegeixen la resta:

- **`Kpapy\Storage\UploadReader`** — `read(string $stored, string $field, array $context): ?string` retorna el contingut del fitxer (`null` = no existeix). Si `url()` retorna `null` i el gestor el pot llegir, kpapy dona una URL signada i temporal i serveix el fitxer ell mateix a `GET /r/{id}/files/{camp}` (tipus MIME detectat pel contingut, `X-Content-Type-Options: nosniff`). Si `url()` retorna `null` i el gestor no el pot llegir, el camp surt com a `null`.
- **`Kpapy\Storage\UploadValidator`** — `validate(UploadedFile $file, string $field, array $context): ?string` substitueix les comprovacions de kpapy (extensió, extensió real, tipus MIME de les imatges, `maxSize`): retorna un missatge d'error per a l'usuari, o `null` si el fitxer és vàlid. Sense aquesta interfície, kpapy valida exactament com abans, també amb un gestor propi.

`LocalUploadHandler` implementa `UploadHandler` i `UploadReader`, i té un mètode públic `directory(array $context): string`. Estén-lo per canviar-ne només una part. La mateixa interfície (nom i mètodes) existeix a kpcrudy: una sola classe serveix per a les dues biblioteques.

---

## Accés des dels callbacks

`getTable()`, `getPrimaryKey()`, `getConfig()`, `getRequest()`, `user()` (l'usuari autenticat, `null` a les rutes públiques), `masksIds()`, `url(?string $id = null, string $suffix = '')` (URL absoluta del recurs o d'un registre, amb l'id emmascarat).

---

## Errors

`Kpapy\Http\ApiException` es converteix en una resposta `problem+json`. Es pot llançar des dels hooks, callbacks i operacions pròpies:

```php
use Kpapy\Http\ApiException;

throw ApiException::conflict('El llibre està en préstec.');
throw ApiException::validation(['isbn' => ['ISBN no vàlid.']]);
throw new ApiException(402, 'payment-required', 'Amplia el teu pla.');
```

Constructors estàtics: `badRequest`, `unauthorized`, `forbidden`, `notFound`, `methodNotAllowed(array $allow)`, `conflict`, `preconditionFailed`, `preconditionRequired`, `unsupportedMediaType`, `validation(array $errors)`, `tooManyRequests(int $retryAfter)`. El títol surt de `Kpapy.problems.<tipus>` (traduït); `type` és `about:blank` o `Config\Kpapy::$problemTypeBase/<tipus>`.

---

## El filtre `kpapy`

Es registra sol (`Kpapy\Config\Registrar`). Per a cada ruta de l'API:

| Filtre | Autenticació | Límit de peticions (per minut) |
|---|---|---|
| `kpapy` | Token obligatori (`401` sense) | `rateLimit` per token |
| `kpapy:optional` | Token si n'hi ha | per token, o `rateLimitPublic` per IP |
| `kpapy:public` | Cap | `rateLimitPublic` per IP |
| `kpapy:login` | Cap | `rateLimitLogin` per IP |
| `kpapy:hmac` | Token HMAC de Shield | `rateLimit` per token |

També: respon les peticions prèvies de CORS (`OPTIONS`) i afegeix `Access-Control-Allow-Origin` (+ `Vary: Origin`, capçaleres exposades `ETag`, `Location`, `Retry-After`) per als orígens de `corsOrigins`; refusa les peticions que no són HTTPS en producció (`requireHttps`); desa l'usuari, els abasts i el token a `Kpapy\Auth\Context`. Les respostes `429` porten `Retry-After`.

---

## Autenticació

### Interfície `Kpapy\Auth\Authenticator`

```php
public function authenticate(IncomingRequest $request): ?array;   // ['user' => …, 'scopes' => llista|null, 'token' => …] o null
```

`Config\Kpapy::$authenticator`: `shield` (`ShieldTokenAuthenticator`, tokens d'accés de Shield: `Authorization: Bearer <token>`), `hmac` (`ShieldHmacAuthenticator`) o la classe d'un autenticador propi. Retorna `null` quan la petició no porta credencials; llança `ApiException::unauthorized()` quan no són vàlides.

### `Kpapy\Auth\Context`

`Context::user()`, `Context::scopes()` (`null` sense token), `Context::token()` (el token d'accés de Shield), `Context::set()`, `Context::reset()`.

### `Kpapy\Auth\TokenService`

Tokens d'accés de Shield per al perfil web de l'aplicació i per a l'inici de sessió de les apps:

| Mètode | Descripció |
|---|---|
| `create(object $user, string $name, array $scopes = [], ?int $lifetime = null, array $options = []): array` | `['token', 'id', 'name', 'scopes', 'expires_at', 'kind']`. El token en clar només es pot veure ara. Sense abasts → `defaultScopes`; `$lifetime` en segons (`null` = `tokenLifetime`, `0` = sense caducitat). `$options`: `kind` (text de fins a 255 caràcters, per defecte cap) marca el token, p. ex. `personal` o `app` |
| `list(object $user, ?string $kind = null): array` | `id`, `name`, `scopes`, `last_used_at`, `expires_at`, `created_at`, `current`, `kind`, `last_ip`. Amb `$kind`, només els tokens d'aquesta marca |
| `revoke(object $user, int $id): bool` | També n'oblida la darrera adreça IP |
| `TokenService::kindOf(object $token): ?string` (estàtic) | La marca d'un token d'accés de Shield (p. ex. `Context::token()`), o `null` |
| `TokenService::cleanScopes(array $scopes): array` (estàtic) | Només els abasts vàlids |

Els tokens personals només es creen des del perfil web (mai des de l'API), de manera que un token filtrat no en pot crear d'altres.

**Marca del token (`kind`).** Es desa a la **columna `secret2` de la taula `auth_identities` de Shield**, que Shield deixa buida als tokens d'accés (només la fa servir als tokens HMAC i a algunes accions, mai a les files `access_token`). Els tokens existents tenen `secret2 = NULL`, és a dir, cap marca. `POST auth/token/refresh` conserva la marca del token que substitueix.

**Darrera adreça IP (`last_ip`).** Cada petició autenticada amb un token dispara l'esdeveniment `kpapy.token.used`. Amb `Config\Kpapy::$trackTokenIps = true`, la darrera adreça IP de cada token també es desa a la taula `kpapy_token_usage` (clau primària `token_id`, `user_id`, `ip_address`, `used_at`; la crea `php spark migrate -n Kpapy`) i `list()` i `GET me/tokens` la retornen com a `last_ip`. Per no escriure a cada petició, la fila només s'actualitza quan canvia la IP o al cap d'un minut. Sense l'opció, `last_ip` és `null`. `Kpapy\Auth\TokenUsage` (`record()`, `lastIps()`, `forget()`) dona accés a la taula.

### `Kpapy\Auth\AuthRoutes`

```php
AuthRoutes::register($routes, 'api/v1');                       // els endpoints de l'API següents
AuthRoutes::web($routes, 'profile', ['filter' => 'session']);  // POST profile/api-logins/{id}/deny (sessió web + CSRF)
echo AuthRoutes::pendingLogins(auth()->id(), 'profile');       // HTML dels inicis de sessió pendents per al perfil
```

`register(RouteCollection $routes, string $prefix = 'api/v1', array $options = [], ?string $loginController = null, ?string $tokenController = null)`: `$options` s'afegeixen a totes les rutes; `$loginController` és el controlador de les rutes `auth/token…` (una subclasse de `Kpapy\Auth\LoginController`) i `$tokenController` el de `me/tokens` (una subclasse de `Kpapy\Auth\TokenController`). `null` fa servir `Config\Kpapy::$loginController` / `$tokenController` (per defecte, les classes de la biblioteca). Qualsevol altra classe llança `InvalidArgumentException`.

```php
AuthRoutes::register($routes, 'api/v1', [], \App\Controllers\Api\Login::class);
```

| Endpoint | Filtre | Descripció |
|---|---|---|
| `POST /auth/token` | `kpapy:login` | Pas 1: `{"email", "password", "device_name"?, "scopes"?}` → `202` amb un repte |
| `POST /auth/token/verify` | `kpapy:login` | Pas 2: `{"challenge", "code"}` → `201` `{"token", "token_type": "Bearer", "expires_at", "scopes"}` |
| `DELETE /auth/token` | `kpapy` | Tanca la sessió: revoca el token actual (`204`) |
| `POST /auth/token/refresh` | `kpapy` | Token nou amb el mateix nom i abasts; l'anterior es revoca (`201`) |
| `GET /me/tokens` | `kpapy` | Tokens de l'usuari (necessita l'abast `tokens.manage`, vegeu més avall) |
| `DELETE /me/tokens/{id}` | `kpapy` | Revoca un token (necessita l'abast `tokens.manage`) |

**L'abast `tokens.manage`.** Un token només pot llistar i revocar els tokens del seu usuari si té abasts il·limitats (`*`) o inclou `Config\Kpapy::$tokensScope` (per defecte `tokens.manage`) o el seu comodí (`tokens.*`); qualsevol altre token rep `403 forbidden`. Les peticions sense token (un autenticador propi sense abasts, `Context::scopes() === null`) i `checkScopes = false` no tenen aquesta restricció. `Config\Kpapy::$tokensScopeRequired = false` torna al comportament de la 1.0 (qualsevol token de l'usuari).

### Inici de sessió de les apps (`Kpapy\Auth\LoginController`)

1. **Credencials.** Es comproven amb els hash de contrasenya de Shield (no s'inicia cap sessió web), amb límit per IP (filtre) i per compte (`rateLimitLogin`). Errònies → `401 invalid-credentials` (genèric); usuari bloquejat o inactiu → `403`.
2. **Segon factor.** Es fa servir el primer mètode de `twoFactorMethods` que l'usuari té actiu, i la resposta és:

```json
{"data": {"challenge": "a31b…", "method": "web", "expires_in": 300, "message": "Open your profile in the web application and enter the code shown there.", "profile_url": "https://example.com/perfil"}}
```

   - `totp`: el codi de l'aplicació d'autenticació de l'usuari;
   - `email`: un codi de 6 xifres per correu;
   - `web`: per als usuaris **sense** 2FA. El codi apareix al seu **perfil web** (`AuthRoutes::pendingLogins()`), on també poden denegar l'inici de sessió. Tenir una sessió web oberta és el segon factor.

   Sense `web` a la llista, els usuaris sense 2FA reben `403 two-factor-required`.
3. **Verificació.** Un codi erroni és `422 invalid-code`; després de `challengeAttempts` errors el repte es tanca. Els reptes són d'un sol ús i caduquen al cap de `challengeLifetime` segons.

Necessita la taula `kpapy_login_challenges` (`php spark migrate -n Kpapy`). Per fer servir una altra configuració (p. ex. en un mòdul), estén `LoginController` i redefineix `setup()`.

### Proveïdors de segon factor

`Config\Kpapy::$twoFactorProviders` (mètode → classe que implementa `Kpapy\Auth\TwoFactor\TwoFactorProvider`):

```php
interface TwoFactorProvider
{
    public function isEnabledFor(object $user): bool;
    public function start(object $user, array $challenge, Challenges $challenges): string;      // missatge per a l'app
    public function verify(object $user, array $challenge, string $code, Challenges $challenges): bool;
}
```

- `EmailProvider`: actiu quan el segon factor per correu de Shield ho està (`Config\Auth::$actions['login'] = Email2FA::class`); estén-lo i redefineix `isEnabledFor()` si cada usuari tria.
- `TotpProvider` (RFC 6238, integrat, sense dependències): estén-lo i redefineix `secretFor(object $user): ?string` (el secret base32 de l'usuari; `null` = no actiu), opcionalment `verifyRecoveryCode()`, i `lastStep()` / `rememberStep()` per refusar codis reutilitzats. Propietats `$digits` (6), `$period` (30), `$window` (1). `codeAt(string $secret, int $step)` i `base32Decode()` són públics.
- `WebCodeProvider`: sempre actiu (el de reserva).

`Kpapy\Auth\Challenges` dona `pendingFor($userId)` i `deny($id, $userId)` per a pàgines de perfil pròpies.

### Esdeveniments

`kpapy.auth.challenge` (`user`, `method`, `device`), `kpapy.auth.login` (`user`, `token_id`, `method`), `kpapy.auth.failed` (`user` o `email`, `method`), `kpapy.auth.logout` (`user`, `token_id`), `kpapy.auth.denied` (`user_id`, `challenge`), `kpapy.token.used` (`user`, `token`, `token_id`, `ip`, `user_agent`; cada petició autenticada amb un token, el dispara el filtre `kpapy`). Per a un registre d'activitat:

```php
\CodeIgniter\Events\Events::on('kpapy.auth.login', static function (array $data): void {
    log_message('info', 'Inici de sessió a l\'API de l\'usuari {id}', ['id' => $data['user']->id]);
});
```

---

## OpenAPI i documentació

### `describe(string $summary, string $description = ''): static`

Nom i descripció del recurs al document OpenAPI. Les operacions pròpies i les accions massives fan servir la seva opció `description`.

### `Kpapy\OpenApi\DocsController::routes(RouteCollection $routes, string $path = 'api/v1/docs', array $options = []): void` (estàtic)

`GET <camí>`: una pàgina de documentació (HTML, compatible amb la CSP, clara i fosca); `GET <camí>/openapi.json`: el document OpenAPI 3.1 (per a Swagger UI, Postman, generadors de codi…); `GET <camí>/try-it.js`: l'script de "Prova-ho". No està disponible en producció si no s'activa `docsInProduction`. Per limitar-la a uns camins o fer servir una altra configuració, estén-la i redefineix `prefix()` i `kpapy()` (i `tryIt()` per decidir "Prova-ho" amb una regla pròpia, p. ex. només per als administradors).

**Prova-ho** (`docsTryIt`; per defecte només fora de producció): cada endpoint té un formulari amb els paràmetres de camí, de consulta i de capçalera (`If-Match` per a `PATCH`, `PUT` i `DELETE`), un camp lliure per a altres paràmetres de consulta (`purge=1`, `filter[year][gte]=2000`…) i, per a `POST`, `PATCH` i `PUT`, un cos JSON omplert a partir de l'esquema (`Kpapy\OpenApi\Example`; des d'aquí no s'envien fitxers). **Send** mostra l'estat, les capçaleres útils (`ETag`, `Location`, `Retry-After`…) i el cos formatat; **Copy as curl** copia la mateixa petició. El quadre de dalt admet un token d'accés o, si l'API té les rutes d'inici de sessió (`AuthRoutes::register()`), inicia la sessió com una app en dos passos (contrasenya i després el codi del segon factor de l'usuari o del perfil web). El token només es guarda a la memòria de la pestanya. Les peticions són reals: mateixos permisos, abasts del token, límits i dades que qualsevol altre client. L'script és un fitxer extern (`try-it.js`): la pàgina no té codi en línia i funciona amb una CSP estricta (`script-src 'self'`, `connect-src 'self'`).

### `Kpapy\OpenApi\Generator`

`generate(?string $prefix = null): array` construeix el document a partir de les rutes registrades amb `Resource::routes()` (cada recurs es descriu sol: camps i tipus, camps obligatoris, filtres, ordenació, includes, idiomes, operacions, errors), dels endpoints d'`AuthRoutes` i dels endpoints propis (vegeu més avall). `Generator::toYaml(array $data)` el converteix a YAML. `php spark kpapy:openapi` l'escriu en un fitxer.

**Endpoints propis.** Els controladors normals (p. ex. `GET api/v1/me`) no són recursos, i per això es descriuen d'una d'aquestes dues maneres:

- l'**opció de ruta `openapi`**, un *Operation Object* d'OpenAPI (`summary`, `description`, `tags`, `parameters`, `requestBody`, `responses`, `security`…). El camí i el mètode són els de la mateixa ruta:

  ```php
  $routes->get('api/v1/me', 'Api\Me::index', ['filter' => 'kpapy', 'openapi' => [
      'tags'      => ['Account'],
      'summary'   => 'Current user',
      'responses' => ['200' => ['description' => 'The authenticated user']],
  ]]);
  ```

- **`Generator::addPath(string $path, string $method, array $operation): void`** (estàtic), per a allò que no és una ruta d'aquesta aplicació o per descriure una ruta des d'un altre lloc (p. ex. `app/Config/Routes.php` o el fitxer de rutes d'un mòdul). `$path` és el camí complet de la ruta amb el prefix de l'API (`api/v1/status/{code}`); `$method` no distingeix majúscules. Una crida posterior per al mateix camí i mètode substitueix l'anterior. `Generator::clearPaths()` les treu (tests).

En tots dos casos kpapy hi afegeix el que falta: `responses` (`200 OK`), els paràmetres del camí (els grups de CodeIgniter com `(:num)` passen a ser `{p1}`, `{p2}`…; els `{nom}` conserven el nom) i, a les rutes amb el filtre `kpapy:public`, `kpapy:optional` o `kpapy:login`, una `security` opcional. Altrament s'aplica la seguretat global del document (token bearer). El `$prefix` de `generate()` (`--prefix` de la comanda, `prefix()` de `DocsController`) els filtra com la resta. Surten a la pàgina de documentació sota la primera etiqueta (`API` si no en tenen), amb **Prova-ho**.

---

## Configuració

`app/Config/Kpapy.php` (`php spark kpapy:publish config`), classe `Config\Kpapy extends \Kpapy\Config\Kpapy`:

| Propietat | Per defecte | Descripció |
|---|---|---|
| `perPage`, `maxPerPage` | `25`, `100` | Registres per pàgina i el màxim de `per_page` |
| `envelope` | `true` | `false`: un registre es retorna sense `{"data": …}` |
| `envelopeKeys` | `data`, `meta`, `links` | Noms de les claus del sobre |
| `strictInput` | `false` | `422` per als camps que no es poden escriure |
| `maxBodySize` | `1024` | KB |
| `inferRules` | `true` | Regles de validació de la taula |
| `requireIfMatch` | `false` | Les escriptures necessiten `If-Match` (`428`) |
| `sensitiveFields` | `password`, `token`, `secret`… | No es retornen si no es demanen |
| `maskIds`, `maskKey`, `idMasker` | `false`, `''`, `DefaultIdMasker` | Identificadors emmascarats |
| `permissionResolver` | `null` | Classe que implementa `Kpapy\PermissionResolver` |
| `denyByDefault` | `false` | Denega el que ningú permet |
| `checkScopes` | `true` | Aplica els abasts del token |
| `authenticator` | `shield` | `shield`, `hmac` o una classe |
| `tokenLifetime` | `2592000` (30 dies) | Segons; `0` = sense caducitat |
| `defaultScopes` | `['*']` | Abasts dels tokens nous |
| `tokensScopeRequired`, `tokensScope` | `true`, `tokens.manage` | `GET`/`DELETE me/tokens` només per a tokens il·limitats o amb aquest abast (`false`: qualsevol token, com a la 1.0) |
| `trackTokenIps` | `false` | Desa la darrera adreça IP de cada token (`kpapy_token_usage`) i la retorna com a `last_ip` |
| `loginController`, `tokenController` | `Kpapy\Auth\LoginController`, `Kpapy\Auth\TokenController` | Controladors d'`AuthRoutes::register()` |
| `twoFactorMethods` | `['totp', 'email', 'web']` | Ordre de preferència |
| `twoFactorProviders` | email, totp, web | Mètode → classe |
| `challengeLifetime`, `challengeAttempts` | `300`, `5` | Segons i intents fallits |
| `profileUrl` | `''` | Perfil web amb els codis d'inici de sessió (s'envia a l'app) |
| `requireHttps` | `true` | En producció |
| `corsOrigins`, `corsHeaders`, `corsMaxAge` | `[]`, …, `3600` | CORS (`*` = qualsevol origen) |
| `rateLimit`, `rateLimitPublic`, `rateLimitLogin` | `120`, `60`, `10` | Peticions per minut (`0` = sense límit) |
| `uploadPath`, `uploadMaxSize` | `WRITEPATH/uploads/kpapy`, `2048` | Fitxers |
| `uploadAllowed`, `imageAllowed` | documents i imatges | Extensions |
| `uploadHandler` | `Kpapy\Storage\LocalUploadHandler` | Classe que desa, esborra i dona la URL dels fitxers ([Fitxers](#fitxers)); per camp: l'opció `handler` |
| `fileUrlLifetime` | `600` | Segons de les URL signades dels fitxers |
| `localeFallbacks` | `[]` | Idiomes de reserva dels camps traduïts |
| `openApiTitle`, `openApiVersion`, `openApiDescription` | `API`, `1.0.0`, `''` | Document OpenAPI |
| `docsInProduction` | `false` | Pàgina de documentació en producció |
| `docsTryIt` | `null` | "Prova-ho" a la pàgina de documentació: `null` només fora de producció, `true` sempre, `false` mai |
| `problemTypeBase` | `''` | URL base dels tipus d'error |
