<?php

declare(strict_types=1);

namespace Kpapy\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuració global de kpapy.
 *
 * Per canviar-la des del projecte crea app/Config/Kpapy.php amb una classe
 * Config\Kpapy que estengui aquesta (php spark kpapy:publish config).
 */
class Kpapy extends BaseConfig
{
    // ------------------------------------------------------------------
    // Llistats
    // ------------------------------------------------------------------

    /**
     * Registres per pàgina per defecte i màxim que pot demanar el client (?per_page=).
     */
    public int $perPage    = 25;
    public int $maxPerPage = 100;

    /**
     * Claus del sobre de les respostes: {"data": ..., "meta": ..., "links": ...}.
     * Amb $envelope = false, un registre es retorna sense sobre (els llistats sempre en porten).
     */
    public bool $envelope = true;

    /**
     * @var array{data: string, meta: string, links: string}
     */
    public array $envelopeKeys = ['data' => 'data', 'meta' => 'meta', 'links' => 'links'];

    // ------------------------------------------------------------------
    // Entrada
    // ------------------------------------------------------------------

    /**
     * true: un camp que no es pot escriure al cos de la petició dona un error 422.
     * false (per defecte): s'ignora en silenci (protecció d'assignació massiva).
     */
    public bool $strictInput = false;

    /**
     * Mida màxima del cos JSON, en KB.
     */
    public int $maxBodySize = 1024;

    /**
     * Dedueix regles de validació de l'estructura de la taula.
     */
    public bool $inferRules = true;

    /**
     * Obliga a enviar If-Match (ETag) per modificar o esborrar: evita sobreescriure
     * canvis d'altres (concurrència optimista).
     */
    public bool $requireIfMatch = false;

    /**
     * Noms de camp (o parts del nom separades per "_") que no surten mai si no es
     * demanen explícitament amb fields().
     *
     * @var list<string>
     */
    public array $sensitiveFields = [
        'password', 'passwd', 'pass', 'pwd', 'contrasenya', 'contrasena',
        'secret', 'token', 'salt', 'hash', 'apikey', 'otp', 'totp', '2fa',
    ];

    // ------------------------------------------------------------------
    // Identificadors emmascarats
    // ------------------------------------------------------------------

    /**
     * Emmascara els identificadors de totes les respostes i URL.
     */
    public bool $maskIds = false;

    /**
     * Clau per emmascarar (32 caràcters o més). Buida: la d'encriptació de CodeIgniter o,
     * si no n'hi ha, writable/kpapy/mask.key.
     */
    public string $maskKey = '';

    /**
     * @var class-string<\Kpapy\IdMasker>
     */
    public string $idMasker = \Kpapy\DefaultIdMasker::class;

    // ------------------------------------------------------------------
    // Permisos
    // ------------------------------------------------------------------

    /**
     * Classe que decideix els permisos de tots els recursos (Kpapy\PermissionResolver).
     *
     * @var class-string<\Kpapy\PermissionResolver>|null
     */
    public ?string $permissionResolver = null;

    /**
     * true: una operació sobre la qual ningú diu res es denega.
     */
    public bool $denyByDefault = false;

    /**
     * Comprova els abasts (scopes) del token: el client només pot fer el que permeten
     * alhora els permisos de l'usuari i els abasts del token. Un abast és
     * "recurs.operació", un comodí "recurs.*" o "*".
     */
    public bool $checkScopes = true;

    // ------------------------------------------------------------------
    // Autenticació
    // ------------------------------------------------------------------

    /**
     * Autenticador per defecte del filtre 'kpapy': 'shield' (tokens d'accés de Shield),
     * 'hmac' (tokens HMAC de Shield), 'none' (API pública) o una classe que implementi
     * Kpapy\Auth\Authenticator.
     */
    public string $authenticator = 'shield';

    /**
     * Durada dels tokens que dona l'inici de sessió de les apps, en segons (0 = sense caducitat).
     */
    public int $tokenLifetime = 2592000;

    /**
     * Abasts per defecte dels tokens nous si el client no en demana.
     *
     * @var list<string>
     */
    public array $defaultScopes = ['*'];

    /**
     * Mètodes de segon factor, per ordre de preferència. El primer que l'usuari tingui
     * actiu és el que es fa servir; 'web' (codi al perfil web) és per als usuaris sense
     * cap altre mètode. Treu-ne 'web' per refusar l'inici de sessió dels usuaris sense 2FA.
     *
     * @var list<string>
     */
    public array $twoFactorMethods = ['totp', 'email', 'web'];

    /**
     * Proveïdors de segon factor: mètode => classe (Kpapy\Auth\TwoFactor\TwoFactorProvider).
     *
     * @var array<string, class-string>
     */
    public array $twoFactorProviders = [
        'email' => \Kpapy\Auth\TwoFactor\EmailProvider::class,
        'totp'  => \Kpapy\Auth\TwoFactor\TotpProvider::class,
        'web'   => \Kpapy\Auth\TwoFactor\WebCodeProvider::class,
    ];

    /**
     * Validesa d'un repte de segon factor (segons) i intents màxims.
     */
    public int $challengeLifetime = 300;
    public int $challengeAttempts = 5;

    /**
     * URL del perfil web on l'usuari veu els codis d'inici de sessió pendents (mètode 'web').
     */
    public string $profileUrl = '';

    /**
     * En producció, refusa les peticions que no són HTTPS (darrere d'un proxy, configura
     * Config\App::$proxyIPs perquè CodeIgniter el detecti).
     */
    public bool $requireHttps = true;

    // ------------------------------------------------------------------
    // CORS i límit de peticions
    // ------------------------------------------------------------------

    /**
     * Orígens permesos ('*' = qualsevol, desaconsellat amb dades privades). Buit: no hi ha CORS.
     *
     * @var list<string>
     */
    public array $corsOrigins = [];

    /**
     * @var list<string>
     */
    public array $corsHeaders = ['Authorization', 'Content-Type', 'If-Match', 'If-None-Match', 'Accept-Language'];

    public int $corsMaxAge = 3600;

    /**
     * Peticions per minut per token (o per IP si no n'hi ha). 0 = sense límit.
     */
    public int $rateLimit       = 120;
    public int $rateLimitPublic = 60;
    public int $rateLimitLogin  = 10;

    // ------------------------------------------------------------------
    // Fitxers
    // ------------------------------------------------------------------

    public string $uploadPath    = WRITEPATH . 'uploads/kpapy';
    public int $uploadMaxSize    = 2048;

    /**
     * @var list<string>
     */
    public array $uploadAllowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'zip', 'docx', 'xlsx', 'odt', 'ods'];

    /**
     * @var list<string>
     */
    public array $imageAllowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Durada de les URL signades dels fitxers privats, en segons.
     */
    public int $fileUrlLifetime = 600;

    /**
     * Gestor que desa, esborra i dona la URL dels fitxers (Kpapy\Storage\UploadHandler).
     * Un camp en pot fer servir un altre amb l'opció 'handler' de fieldType().
     *
     * @var class-string<\Kpapy\Storage\UploadHandler>
     */
    public string $uploadHandler = \Kpapy\Storage\LocalUploadHandler::class;

    // ------------------------------------------------------------------
    // Idiomes dels camps traduïts
    // ------------------------------------------------------------------

    /**
     * Cadena de reserva quan un camp traduït no existeix en l'idioma demanat.
     * Buit: l'idioma per defecte de l'aplicació.
     *
     * @var list<string>
     */
    public array $localeFallbacks = [];

    // ------------------------------------------------------------------
    // Documentació OpenAPI
    // ------------------------------------------------------------------

    public string $openApiTitle   = 'API';
    public string $openApiVersion = '1.0.0';
    public string $openApiDescription = '';

    /**
     * La pàgina de documentació interactiva només es mostra fora de producció si és false.
     */
    public bool $docsInProduction = false;

    /**
     * Opció "Prova-ho" de la pàgina de documentació: un formulari a cada endpoint per enviar
     * peticions reals amb un token (o iniciant la sessió com una app) i veure la resposta.
     * null: només fora de producció; true: sempre que es mostri la pàgina; false: mai.
     * Les peticions passen pels mateixos permisos, abasts i límits que qualsevol altra.
     */
    public ?bool $docsTryIt = null;

    /**
     * Base de les URL dels tipus d'error (problem+json). Buit: about:blank amb el codi.
     */
    public string $problemTypeBase = '';
}
