<?php

declare(strict_types=1);

namespace Kpapy;

use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Model;
use CodeIgniter\Router\RouteCollection;
use Kpapy\Auth\Context;
use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Http\ApiException;
use Kpapy\Http\Responder;
use RuntimeException;
use Throwable;

/**
 * Recurs de l'API REST generat a partir d'una taula o d'un model.
 *
 *     public function books()
 *     {
 *         return (new Resource())
 *             ->setTable('books')
 *             ->setResource('library-books')
 *             ->setRelation('author_id', 'authors', ['id', 'name'], ['as' => 'author'])
 *             ->filters(['author_id', 'year'])
 *             ->render();
 *     }
 *
 *     // app/Config/Routes.php
 *     Resource::routes($routes, 'api/v1/books', 'Api\Books::books', ['filter' => 'kpapy']);
 */
class Resource
{
    use Concerns\Schema;
    use Concerns\Reads;
    use Concerns\Writes;
    use Concerns\Describes;

    /**
     * Paraules de la URL que no poden ser claus d'operacions pròpies.
     */
    public const RESERVED = ['bulk', 'restore', 'files'];

    /**
     * Operacions pròpies del recurs (per als permisos i el catàleg).
     */
    public const OPERATIONS = ['list', 'show', 'add', 'edit', 'delete', 'trash', 'restore', 'purge', 'search', 'filters', 'bulk'];

    /**
     * Permisos que en pressuposen d'altres.
     */
    public const IMPLIED_PERMISSIONS = [
        'add'     => ['list'],
        'edit'    => ['list', 'show'],
        'delete'  => ['list'],
        'trash'   => ['list'],
        'restore' => ['trash'],
        'purge'   => ['trash'],
        'search'  => ['list'],
        'filters' => ['list'],
        'bulk'    => ['list'],
    ];

    protected KpapyConfig $config;
    protected IncomingRequest|CLIRequest $request;
    protected Responder $responder;

    protected ?string $table = null;
    protected ?Model $model  = null;
    protected ?BaseConnection $db = null;
    protected ?string $primaryKey = null;
    protected ?string $resource   = null;
    protected ?string $basePath   = null;

    /**
     * @var array<string, mixed>|list<array{0: string, 1: mixed}>
     */
    protected array $wheres = [];

    /**
     * @var array<string, bool|Closure>
     */
    protected array $permissions = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $operations = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $bulkActions = [];

    /**
     * @var array<string, Closure>
     */
    protected array $hooks = [];

    /**
     * Gestors de fitxers ja creats, per camp.
     *
     * @var array<string, \Kpapy\Storage\UploadHandler>
     */
    protected array $uploadHandlers = [];

    protected PermissionResolver|Closure|null $resolver = null;
    protected bool $resolverResolved = false;

    /**
     * @var array<string, bool|null>
     */
    protected array $resolverCache = [];

    protected ?bool $maskIds = null;
    protected ?string $maskContext = null;
    protected ?IdMasker $masker = null;

    /**
     * @var list<array{0: string, 1: string}>
     */
    protected array $descriptions = [];

    protected static bool $routesChecked = false;

    public function __construct(?KpapyConfig $config = null)
    {
        $config ??= config('Kpapy');

        $this->config    = $config instanceof KpapyConfig ? $config : new KpapyConfig();
        $this->request   = service('request');
        $this->responder = new Responder($this->config);
    }

    // ------------------------------------------------------------------
    // Origen de dades
    // ------------------------------------------------------------------

    public function setTable(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    public function setModel(Model|string $model): static
    {
        $this->model = is_string($model) ? model($model, false) : $model;

        if (! $this->model instanceof Model) {
            throw new RuntimeException(lang('Kpapy.exceptions.modelNotFound', [is_string($model) ? $model : $model::class]));
        }

        return $this;
    }

    public function setDatabase(BaseConnection|string $db): static
    {
        $this->db = is_string($db) ? db_connect($db) : $db;

        return $this;
    }

    public function setPrimaryKey(string $primaryKey): static
    {
        $this->primaryKey = $primaryKey;

        return $this;
    }

    /**
     * Limita tots els registres del recurs (lectura, escriptura, esborrat, accions massives).
     * Les condicions d'igualtat simples s'imposen en escriure: el client no les pot canviar.
     */
    public function where(array|string $field, mixed $value = null): static
    {
        if (is_array($field)) {
            foreach ($field as $key => $item) {
                $this->wheres[] = [(string) $key, $item];
            }
        } else {
            $this->wheres[] = [$field, $value];
        }

        return $this;
    }

    /**
     * Ruta base del recurs quan no es registra amb Resource::routes() (p. ex. 'api/v1/books').
     */
    public function setBasePath(string $path): static
    {
        $this->basePath = trim($path, '/');

        return $this;
    }

    // ------------------------------------------------------------------
    // Permisos
    // ------------------------------------------------------------------

    /**
     * Nom del recurs per al resolutor de permisos (p. ex. 'library-books'). Per defecte, la taula.
     */
    public function setResource(string $resource): static
    {
        $this->resource      = $resource;
        $this->resolverCache = [];

        return $this;
    }

    public function getResource(): string
    {
        return $this->resource ?? (string) $this->getTable();
    }

    public function setPermissionResolver(PermissionResolver|Closure|null $resolver): static
    {
        $this->resolver         = $resolver;
        $this->resolverResolved = true;
        $this->resolverCache    = [];

        return $this;
    }

    /**
     * Permet o denega una operació; amb una closure fn (?array $row, string $operation): bool
     * es decideix registre a registre.
     */
    public function setPermission(string $operation, bool|Closure $allowed): static
    {
        $this->permissions[$operation] = $allowed;

        return $this;
    }

    public function unsetList(): static
    {
        return $this->setPermission('list', false);
    }

    public function unsetShow(): static
    {
        return $this->setPermission('show', false);
    }

    public function unsetAdd(): static
    {
        return $this->setPermission('add', false);
    }

    public function unsetEdit(): static
    {
        return $this->setPermission('edit', false);
    }

    public function unsetDelete(): static
    {
        return $this->setPermission('delete', false);
    }

    public function unsetTrash(): static
    {
        return $this->setPermission('trash', false);
    }

    public function unsetPurge(): static
    {
        return $this->setPermission('purge', false);
    }

    public function unsetBulk(): static
    {
        return $this->setPermission('bulk', false);
    }

    /**
     * Només lectura: sense alta, edició, esborrat, restauració ni accions massives.
     */
    public function readOnly(): static
    {
        foreach (['add', 'edit', 'delete', 'restore', 'purge', 'bulk'] as $operation) {
            $this->setPermission($operation, false);
        }

        return $this;
    }

    /**
     * Si el client pot fer una operació, en general o sobre un registre. Combina el
     * resolutor, setPermission(), denyByDefault i els abasts del token.
     *
     * @param array<string, mixed>|null $row
     */
    public function isAllowed(string $operation, ?array $row = null): bool
    {
        return $this->can($operation, $row);
    }

    /**
     * Totes les operacions per les quals aquest recurs pot preguntar als permisos.
     *
     * @return list<string>
     */
    public function permissionCatalogue(): array
    {
        $this->boot();

        $builtin = ['list', 'show', 'add', 'edit', 'delete', 'search', 'filters', 'bulk'];

        if ($this->softDeletes) {
            array_push($builtin, 'trash', 'restore', 'purge');
        }

        $builtin = array_filter($builtin, fn (string $operation): bool => ($this->permissions[$operation] ?? null) !== false);
        $custom  = array_column($this->operations, 'permission');

        foreach ($this->bulkActions as $action) {
            if (is_string($action['permission'] ?? null) && $action['permission'] !== '') {
                $custom[] = $action['permission'];
            }
        }

        return array_values(array_unique([...$builtin, ...$custom]));
    }

    /**
     * @param list<string> $operations
     *
     * @return list<string>
     */
    public static function impliedPermissions(array $operations): array
    {
        $result  = [];
        $pending = array_values($operations);

        while ($pending !== []) {
            $operation = array_shift($pending);

            if (in_array($operation, $result, true)) {
                continue;
            }

            $result[] = $operation;
            $pending  = [...$pending, ...(self::IMPLIED_PERMISSIONS[$operation] ?? [])];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    protected function can(string $operation, ?array $row = null): bool
    {
        if (in_array($operation, ['trash', 'restore', 'purge'], true)) {
            if (! $this->softDeletes && $operation !== 'purge') {
                return false;
            }

            if ($operation !== 'trash' && $this->softDeletes && ! $this->can('trash', $row)) {
                return false;
            }
        }

        if (! $this->scopeAllows($operation)) {
            return false;
        }

        $resolved = $this->resolvePermission($operation, $row);

        if ($resolved === false) {
            return false;
        }

        $rule = $this->permissions[$operation] ?? null;

        if ($rule !== null) {
            return $rule instanceof Closure ? (bool) $rule($row, $operation) : $rule;
        }

        return $resolved ?? ! $this->config->denyByDefault;
    }

    /**
     * Abasts del token: "*", "recurs.*" o "recurs.operació" (search, filters i bulk
     * també s'accepten amb "recurs.list").
     */
    protected function scopeAllows(string $operation): bool
    {
        if (! $this->config->checkScopes) {
            return true;
        }

        $scopes = Context::scopes();

        if ($scopes === null) {
            return true;
        }

        $resource   = $this->getResource();
        $candidates = ['*', $resource . '.*', $resource . '.' . $operation];

        if (in_array($operation, ['search', 'filters', 'bulk'], true)) {
            $candidates[] = $resource . '.list';
        }

        return array_intersect($candidates, $scopes) !== [];
    }

    /**
     * @param array<string, mixed>|null $row
     */
    protected function resolvePermission(string $operation, ?array $row): ?bool
    {
        if (! $this->resolverResolved) {
            $this->resolverResolved = true;
            $class                  = $this->config->permissionResolver;

            if ($class !== null && $class !== '') {
                $resolver = new $class();

                if (! $resolver instanceof PermissionResolver) {
                    throw new RuntimeException(lang('Kpapy.exceptions.invalidResolver', [$class]));
                }

                $this->resolver = $resolver;
            }
        }

        if ($this->resolver === null) {
            return null;
        }

        if ($row === null && array_key_exists($operation, $this->resolverCache)) {
            return $this->resolverCache[$operation];
        }

        $result = $this->resolver instanceof PermissionResolver
            ? $this->resolver->allows($this->getResource(), $operation, $row, $this)
            : ($this->resolver)($this->getResource(), $operation, $row, $this);
        $result = $result === null ? null : (bool) $result;

        if ($row === null) {
            $this->resolverCache[$operation] = $result;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Operacions pròpies, accions massives i hooks
    // ------------------------------------------------------------------

    /**
     * Operació pròpia: POST base/{id}/{clau} (sobre un registre) o POST base/{clau}.
     *
     * fn (?string $id, ?array $row, Resource $api): mixed
     *  - array: es retorna com a "data" (200, o 'status')
     *  - string: {"data": {"message": ...}}
     *  - ResponseInterface: tal qual
     *  - null: 204; false: 409 (o llança ApiException amb $api->fail())
     *
     * Opcions: 'row' (true), 'method' ('post' o 'get'), 'permission' (per defecte la clau),
     * 'visible' fn (?array $row): bool, 'trash' (sobre registres de la paperera), 'status',
     * 'description' (OpenAPI).
     *
     * @param array<string, mixed> $options
     */
    public function addOperation(string $key, Closure $callback, array $options = []): static
    {
        if (! preg_match('/^[a-z][a-z0-9_-]*$/', $key) || in_array($key, [...self::RESERVED, ...self::OPERATIONS], true)) {
            throw new RuntimeException(lang('Kpapy.exceptions.invalidOperation', [$key]));
        }

        $this->operations[$key] = [
            'callback'    => $callback,
            'row'         => (bool) ($options['row'] ?? true),
            'method'      => strtolower((string) ($options['method'] ?? 'post')) === 'get' ? 'GET' : 'POST',
            'permission'  => (string) ($options['permission'] ?? $key),
            'visible'     => $options['visible'] ?? null,
            'trash'       => (bool) ($options['trash'] ?? false),
            'status'      => (int) ($options['status'] ?? 200),
            'description' => (string) ($options['description'] ?? ''),
        ];

        return $this;
    }

    /**
     * Acció massiva: POST base/bulk {"action": clau, "ids": [...]}. Rep els id ja filtrats
     * per where() i pel permís de cada registre: fn (array $ids, Resource $api): mixed.
     *
     * @param array<string, mixed> $options 'permission', 'description'
     */
    public function addBulkAction(string $key, Closure $callback, array $options = []): static
    {
        $this->bulkActions[$key] = [
            'callback'    => $callback,
            'permission'  => $options['permission'] ?? null,
            'description' => (string) ($options['description'] ?? ''),
        ];

        return $this;
    }

    /**
     * Atura una operació amb un error per al client (dins de hooks i operacions pròpies).
     *
     * @return never
     */
    public function fail(string $message, int $status = 409, string $type = 'operation-failed'): never
    {
        throw new ApiException($status, $type, $message);
    }

    public function beforeInsert(Closure $callback): static
    {
        return $this->setHook('beforeInsert', $callback);
    }

    public function afterInsert(Closure $callback): static
    {
        return $this->setHook('afterInsert', $callback);
    }

    public function beforeUpdate(Closure $callback): static
    {
        return $this->setHook('beforeUpdate', $callback);
    }

    public function afterUpdate(Closure $callback): static
    {
        return $this->setHook('afterUpdate', $callback);
    }

    public function beforeDelete(Closure $callback): static
    {
        return $this->setHook('beforeDelete', $callback);
    }

    public function afterDelete(Closure $callback): static
    {
        return $this->setHook('afterDelete', $callback);
    }

    public function afterRestore(Closure $callback): static
    {
        return $this->setHook('afterRestore', $callback);
    }

    /**
     * Després de qualsevol escriptura: fn (string $operation, string $id, array $data, ?array $old): void
     */
    public function afterWrite(Closure $callback): static
    {
        return $this->setHook('afterWrite', $callback);
    }

    protected function setHook(string $name, Closure $callback): static
    {
        $this->hooks[$name] = $callback;

        return $this;
    }

    /**
     * Descripció del recurs per a la documentació OpenAPI.
     */
    public function describe(string $summary, string $description = ''): static
    {
        $this->descriptions[] = [$summary, $description];

        return $this;
    }

    // ------------------------------------------------------------------
    // Identificadors emmascarats
    // ------------------------------------------------------------------

    public function maskIds(bool $enabled = true, ?string $context = null, IdMasker|string|null $masker = null): static
    {
        $this->maskIds     = $enabled;
        $this->maskContext = $context;

        if ($masker !== null) {
            $this->masker = is_string($masker) ? new $masker($this->config) : $masker;
        }

        return $this;
    }

    public function masksIds(): bool
    {
        return $this->maskIds ?? $this->config->maskIds;
    }

    public function encodeId(int|string $id, ?string $context = null): string
    {
        return $this->masksIds() ? $this->masker()->encode((string) $id, $context ?? $this->maskContext ?? (string) $this->getTable()) : (string) $id;
    }

    public function decodeId(string $value, ?string $context = null): ?string
    {
        if (! $this->masksIds()) {
            return $value;
        }

        return $this->masker()->decode($value, $context ?? $this->maskContext ?? (string) $this->getTable());
    }

    protected function masker(): IdMasker
    {
        if ($this->masker === null) {
            $class = $this->config->idMasker;
            $this->masker = new $class($this->config);

            if (! $this->masker instanceof IdMasker) {
                throw new RuntimeException(lang('Kpapy.exceptions.invalidMasker', [$class]));
            }
        }

        return $this->masker;
    }

    // ------------------------------------------------------------------
    // Accés des dels callbacks
    // ------------------------------------------------------------------

    public function getTable(): ?string
    {
        return $this->table ?? $this->model?->table;
    }

    public function getPrimaryKey(): string
    {
        $this->boot();

        return (string) $this->primaryKey;
    }

    public function getConfig(): KpapyConfig
    {
        return $this->config;
    }

    public function getRequest(): IncomingRequest|CLIRequest
    {
        return $this->request;
    }

    /**
     * Usuari autenticat de la petició (o null a les rutes públiques).
     */
    public function user(): ?object
    {
        return Context::user();
    }

    /**
     * URL absoluta del recurs o d'un registre.
     */
    public function url(?string $id = null, string $suffix = ''): string
    {
        $base = rtrim(site_url($this->basePath()), '/');

        if ($id !== null) {
            $base .= '/' . rawurlencode($this->encodeId($id));
        }

        return $suffix === '' ? $base : $base . '/' . ltrim($suffix, '/');
    }

    // ------------------------------------------------------------------
    // Execució
    // ------------------------------------------------------------------

    /**
     * Atén la petició: resol el mètode i la ruta, comprova els permisos i respon en JSON.
     */
    public function render(): ResponseInterface
    {
        if (static::$describing) {
            static::$described = $this->definition();

            // Una resposta pròpia: la compartida és la de la pàgina que genera la documentació
            return \Config\Services::response(null, false)->setStatusCode(204);
        }

        try {
            $this->boot();

            return $this->dispatch();
        } catch (ApiException $e) {
            return $this->responder->problem($e);
        } catch (Throwable $e) {
            return $this->responder->exception($e);
        }
    }

    protected function dispatch(): ResponseInterface
    {
        $method   = strtoupper($this->request->getMethod());
        $segments = $this->segments();
        $count    = count($segments);

        if ($method === 'OPTIONS') {
            return $this->responder->noContent()->setHeader('Allow', implode(', ', [...$this->allowedMethods($segments), 'OPTIONS']));
        }

        // base
        if ($count === 0) {
            return match ($method) {
                'GET'   => $this->listOperation(),
                'POST'  => $this->createOperation(),
                default => throw ApiException::methodNotAllowed($this->allowedMethods($segments)),
            };
        }

        // base/bulk o base/{operació sense registre}
        if ($count === 1 && $segments[0] === 'bulk') {
            return $method === 'POST' ? $this->bulkOperation() : throw ApiException::methodNotAllowed(['POST']);
        }

        if ($count === 1 && isset($this->operations[$segments[0]]) && ! $this->operations[$segments[0]]['row']) {
            return $this->customOperation($segments[0], null, $method);
        }

        // base/{id}...
        $id = $this->decodeId($segments[0]);

        if ($id === null || ! $this->isValidId($id)) {
            throw ApiException::notFound();
        }

        if ($count === 1) {
            return match ($method) {
                'GET'          => $this->showOperation($id),
                'PATCH', 'PUT' => $this->updateOperation($id, $method === 'PUT'),
                'DELETE'       => $this->deleteOperation($id),
                default        => throw ApiException::methodNotAllowed($this->allowedMethods($segments)),
            };
        }

        if ($count === 2 && $segments[1] === 'restore') {
            return $method === 'POST' ? $this->restoreOperation($id) : throw ApiException::methodNotAllowed(['POST']);
        }

        if ($count === 2 && isset($this->operations[$segments[1]]) && $this->operations[$segments[1]]['row']) {
            return $this->customOperation($segments[1], $id, $method);
        }

        if ($count === 3 && $segments[1] === 'files') {
            return match ($method) {
                'GET'   => $this->downloadOperation($id, $segments[2]),
                'POST'  => $this->uploadOperation($id, $segments[2]),
                default => throw ApiException::methodNotAllowed(['GET', 'POST']),
            };
        }

        throw ApiException::notFound();
    }

    /**
     * @param list<string> $segments
     *
     * @return list<string>
     */
    protected function allowedMethods(array $segments): array
    {
        $methods = $segments === []
            ? ['GET' => $this->can('list'), 'POST' => $this->can('add')]
            : ['GET' => $this->can('show'), 'PATCH' => $this->can('edit'), 'PUT' => $this->can('edit'), 'DELETE' => $this->can('delete')];

        return array_keys(array_filter($methods));
    }

    /**
     * Segments de la URL després de la base del recurs.
     *
     * @return list<string>
     */
    protected function segments(): array
    {
        $path = trim($this->request->getPath(), '/');
        $base = str_replace('#', '\\#', $this->basePath());

        // La base pot ser un patró de CodeIgniter (p. ex. authors/([0-9]+)/books)
        if (@preg_match('#^' . $base . '(?:/(?<rest>.*))?$#u', $path, $match) !== 1) {
            throw ApiException::notFound();
        }

        $rest = $match['rest'] ?? '';
        $rest = trim((string) $rest, '/');

        return $rest === '' ? [] : array_map('rawurldecode', explode('/', $rest));
    }

    /**
     * Ruta base del recurs: setBasePath() o la ruta de Resource::routes() que ha atès la petició.
     */
    protected function basePath(): string
    {
        if ($this->basePath !== null) {
            return $this->basePath;
        }

        try {
            $matched = service('router')->getMatchedRoute();
        } catch (Throwable) {
            $matched = null;
        }

        $key = is_array($matched) ? (string) ($matched[0] ?? '') : '';

        if (str_ends_with($key, '/(.*)')) {
            $key = substr($key, 0, -5);
        }

        // Rutes amb paràmetres (p. ex. authors/([0-9]+)/books): es fa servir el patró
        $this->basePath = trim($key, '/');

        return $this->basePath;
    }

    /**
     * Registra totes les rutes d'un recurs amb un sol controlador.
     * Només la ruta base porta el nom ('as').
     *
     * @param array<string, mixed> $options
     */
    public static function routes(RouteCollection $routes, string $path, string|\Closure $handler, array $options = []): void
    {
        $path    = trim($path, '/');
        $verbs   = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $options['kpapy'] = true;

        $routes->match($verbs, $path, $handler, $options);

        unset($options['as']);
        $routes->match($verbs, $path . '/(:any)', $handler, $options);
    }
}
