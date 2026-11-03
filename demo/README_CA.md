# kpapy · aplicació de demostració

[English](README_EN.md) · **Català**

Una petita **API de biblioteca** feta amb kpapy: llibres, autors, etiquetes i els préstecs de l'usuari identificat, amb usuaris de prova, l'inici de sessió de les apps amb els dos tipus de segon factor, una **consola web** per enviar peticions i la **pàgina de documentació**. Cada recurs són unes poques línies a [src/Demo/Controllers/Api.php](../src/Demo/Controllers/Api.php).

La demo va dins de la llibreria (`src/Demo`) i s'instal·la en qualsevol projecte CodeIgniter 4 que tingui kpapy i **CodeIgniter Shield**. No copia ni modifica cap fitxer del projecte, i en desinstal·lar-la el projecte queda com estava.

> La consola dona a qualsevol un token dels usuaris de prova: la demo **mai** no se serveix en producció si no s'instal·la amb `--allow-production`.

## Instal·lar-la al teu projecte

```bash
php spark migrate --all            # taules de Shield i de kpapy (kpapy_login_challenges)
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

Les rutes de l'API (`kpapy-demo/api/*`) fan servir tokens, no galetes: exclou-les del filtre CSRF de CodeIgniter (`'csrf' => ['except' => ['kpapy-demo/api/*']]`), com a qualsevol API de kpapy. Shield també necessita `Config\Security::$csrfProtection = 'session'`.

Altres accions:

```bash
php spark kpapy:demo status       # taules i files
php spark kpapy:demo reset        # torna a les dades inicials
php spark kpapy:demo uninstall    # taules, usuaris de prova (i els seus tokens), portades i marca
```

Què crea la instal·lació: les taules `kpapy_demo_*`, dos usuaris de Shield, el fitxer de marca `writable/kpapy-demo.json` (amb la clau d'emmascarament pròpia de la demo i el secret TOTP de l'administrador) i, quan es pugen portades, `writable/uploads/kpapy-demo`.

## Executar el projecte d'aquesta carpeta

Aquesta carpeta també és un projecte CodeIgniter preparat per provar la demo i executar els tests:

```bash
composer install
# .env: configuració de la base de dades (amb valors genèrics) i app.baseURL = 'http://localhost:8082/'
php spark migrate --all
php spark kpapy:demo install
php spark serve --port 8082
```

Obre <http://localhost:8082>: l'arrel redirigeix a `/kpapy-demo`. El projecte activa una Content-Security-Policy estricta (`app/Config/App.php`) per mostrar que les pàgines hi funcionen, i exclou `kpapy-demo/api/*` del CSRF.

Tests (SQLite en memòria): `vendor/bin/phpunit`.

## Usuaris i el seu segon factor

| Usuari | Contrasenya | Rol | Segon factor a l'inici de sessió de les apps |
|---|---|---|---|
| `admin@kpapy.demo` | `kpapy-demo-admin` | tot | **Aplicació d'autenticació (TOTP)**: el seu "perfil web" (`/kpapy-demo/profile/admin`) mostra el codi que donaria ara una app, i el secret per afegir-lo a una app real |
| `reader@kpapy.demo` | `kpapy-demo-reader` | consultar, agafar llibres en préstec, retornar els seus préstecs | **Cap**: el codi de cada inici de sessió apareix al seu "perfil web" (`/kpapy-demo/profile/reader`), on també es pot denegar |

En una aplicació real, el "perfil web" és la pàgina de perfil del web, darrere de l'inici de sessió habitual; aquí és oberta perquè es pugui provar el procés.

## La consola — `/kpapy-demo`

- **Obtenir un token**: tokens ràpids de cada usuari, i també un token del lector amb **abasts** que només permeten llistar i veure llibres (prova un `POST`: `403`).
- **Iniciar la sessió com una app**: correu i contrasenya → repte (`totp` o `web`) → obre el perfil web → escriu el codi → token.
- **Petició**: mètode, camí i cos JSON, amb exemples (includes, selecció de camps, filtres, cerca, idiomes, alta amb errors de validació, l'operació `lend`, els meus préstecs, una acció massiva, paginació per cursor, els meus tokens, el catàleg públic). La resposta mostra el codi d'estat, l'`ETag` i el cos.

La pàgina de la consola no té JavaScript en línia (un mòdul ES extern), com totes les pàgines de la demo.

## Recursos de l'API

Tots sota `/kpapy-demo/api/v1/`. Els permisos surten d'un sol resolutor, [Libraries/DemoPermissions.php](../src/Demo/Libraries/DemoPermissions.php), amb `denyByDefault`.

| Camí | Recurs | Mostra |
|---|---|---|
| `books` | `library-books` | Autor 1-N (`?include=author`), etiquetes N-N, resum traduït a en/ca/es (`?lang=`, `Accept-Language`, `?lang=*`), multiselecció `audience`, portada privada amb URL signades, regles (ISBN únic), filtres amb operadors, cerca, paperera (enviar, restaurar, esborrar definitivament), operació pròpia `POST books/{id}/lend` (només llibres disponibles), acció massiva `mark-unavailable` |
| `authors` | `library-authors` | Paginació per cursor (`meta.next_cursor`), camp calculat `books`, un hook que impedeix esborrar autors amb llibres |
| `tags` | `library-tags` | El recurs més senzill |
| `my/loans` | `library-loans` | **Registres propis** (`where(user_id)`), només lectura, operació pròpia `POST my/loans/{id}/return` |
| `public/books` | `library-public` | Catàleg públic **sense token** (`kpapy:public`), només llibres disponibles |
| `auth/token`, `auth/token/verify`, `auth/token/refresh`, `me/tokens` | — | Inici de sessió de les apps amb 2FA, renovació, tokens de l'usuari |
| `docs`, `docs/openapi.json` | — | Pàgina de documentació i document OpenAPI 3.1 |

Tots els id van **emmascarats** (amb la clau pròpia de la demo): copia'ls de les respostes.

## Com està feta

| Element | Fitxer |
|---|---|
| Comanda `kpapy:demo` | [src/Commands/Demo.php](../src/Commands/Demo.php) |
| Instal·lació i desinstal·lació | [src/Demo/Installer.php](../src/Demo/Installer.php) |
| Noms, rutes, usuaris | [src/Demo/Demo.php](../src/Demo/Demo.php) · [src/Config/Routes.php](../src/Config/Routes.php) |
| Configuració de kpapy de la demo (id emmascarats, resolutor, mètodes de 2FA) | [src/Demo/DemoConfig.php](../src/Demo/DemoConfig.php) |
| Recursos de l'API | [src/Demo/Controllers/Api.php](../src/Demo/Controllers/Api.php) |
| Inici de sessió de les apps amb la configuració de la demo | [src/Demo/Controllers/DemoLogin.php](../src/Demo/Controllers/DemoLogin.php) |
| Permisos i TOTP | [src/Demo/Libraries](../src/Demo/Libraries) |
| Consola i perfils web | [src/Demo/Controllers/Console.php](../src/Demo/Controllers/Console.php) · [src/Demo/Views](../src/Demo/Views) |
| Migracions i dades | [src/Demo/Database](../src/Demo/Database) |
