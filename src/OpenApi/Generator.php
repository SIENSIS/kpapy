<?php

declare(strict_types=1);

namespace Kpapy\OpenApi;

use Closure;
use CodeIgniter\Router\RouteCollection;
use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Resource;
use Throwable;

/**
 * Genera el document OpenAPI 3.1 de les rutes registrades amb Resource::routes() i
 * AuthRoutes::register().
 *
 *     $spec = (new Generator())->generate();            // array
 *     php spark kpapy:openapi --output public/api/openapi.json
 */
class Generator
{
    protected KpapyConfig $config;

    /**
     * @var list<string> Errors en descriure alguna ruta (es mostren a la comanda)
     */
    public array $warnings = [];

    public function __construct(?KpapyConfig $config = null, protected ?RouteCollection $routes = null)
    {
        $config ??= config('Kpapy');
        $this->config = $config instanceof KpapyConfig ? $config : new KpapyConfig();
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(?string $prefix = null): array
    {
        $paths      = [];
        $schemas    = ['Problem' => $this->problemSchema()];
        $authPrefix = null;

        foreach ($this->resourceRoutes() as $path => $route) {
            if ($prefix !== null && ! str_starts_with($path, trim($prefix, '/'))) {
                continue;
            }

            $definition = $this->describe($route['handler'], $path);

            if ($definition === null) {
                continue;
            }

            $name = $this->schemaName($definition['resource']);
            $schemas[$name]            = ['type' => 'object', 'properties' => $definition['output']];
            $schemas[$name . 'Input']  = ['type' => 'object', 'properties' => $definition['input']] + ($definition['required'] === [] ? [] : ['required' => $definition['required']]);

            foreach ($this->resourcePaths($path, $definition, $name, $route['public']) as $url => $item) {
                $paths[$url] = $item;
            }
        }

        foreach ($this->collection()->getRoutes('POST') as $key => $handler) {
            $class = is_string($handler) ? ltrim(explode('::', $handler)[0], '\\') : '';

            if ($class !== '' && str_ends_with($handler, '::token') && is_a($class, \Kpapy\Auth\LoginController::class, true) && str_ends_with((string) $key, 'auth/token')) {
                $authPrefix = substr((string) $key, 0, -strlen('/auth/token'));
            }
        }

        if ($authPrefix !== null && ($prefix === null || str_starts_with($authPrefix, trim($prefix, '/')))) {
            $paths += $this->authPaths($authPrefix);
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info'    => array_filter([
                'title'       => $this->config->openApiTitle,
                'version'     => $this->config->openApiVersion,
                'description' => $this->config->openApiDescription,
            ]),
            'servers'    => [['url' => rtrim(site_url('/'), '/')]],
            'paths'      => $paths,
            'components' => [
                'schemas'         => $schemas,
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Shield access token']],
                'responses'       => $this->commonResponses(),
            ],
            'security' => [['bearer' => []]],
        ];
    }

    /**
     * Rutes base dels recursos: camí => [handler, public].
     *
     * @return array<string, array{handler: Closure|string, public: bool}>
     */
    public function resourceRoutes(): array
    {
        $collection = $this->collection();
        $result     = [];

        foreach (['GET', 'get'] as $verb) {
            foreach ($collection->getRoutes($verb) as $key => $handler) {
                $key     = (string) $key;
                $options = $collection->getRoutesOptions($key, $verb);

                if (empty($options['kpapy']) || str_ends_with($key, '/(.*)')) {
                    continue;
                }

                $filters = (array) ($options['filter'] ?? []);
                $public  = (bool) array_filter($filters, static fn ($f): bool => is_string($f) && (str_contains($f, ':public') || str_contains($f, ':optional')));

                $result[$key] = ['handler' => $handler, 'public' => $public];
            }
        }

        return $result;
    }

    /**
     * Descripció d'un recurs: crida el controlador en mode descripció.
     *
     * @return array<string, mixed>|null
     */
    public function describe(Closure|string $handler, string $path): ?array
    {
        Resource::$describing = true;
        Resource::$described  = null;

        try {
            if ($handler instanceof Closure) {
                $handler();
            } else {
                [$class, $method] = explode('::', ltrim($handler, '\\')) + [1 => 'index'];
                $method           = explode('/', $method)[0];
                $controller       = new $class();
                $controller->initController(service('request'), service('response'), service('logger'));
                $controller->{$method}();
            }
        } catch (Throwable $e) {
            $this->warnings[] = "{$path}: " . $e->getMessage();
        } finally {
            Resource::$describing = false;
        }

        return Resource::$described;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, array<string, mixed>>
     */
    protected function resourcePaths(string $path, array $d, string $name, bool $public): array
    {
        $base     = '/' . $this->openApiPath($path);
        $tag      = $d['summary'];
        $disabled = $d['disabled'];
        $security = $public ? ['security' => [[], ['bearer' => []]]] : [];
        $ref      = ['$ref' => '#/components/schemas/' . $name];
        $item     = ['type' => 'object', 'properties' => ['data' => $ref, 'links' => ['type' => 'object']]];
        $idParam  = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        $errors   = static fn (array $codes): array => array_combine(array_map('strval', $codes), array_map(static fn (int $c): array => ['$ref' => '#/components/responses/E' . $c], $codes));
        $json     = static fn (array $schema): array => ['content' => ['application/json' => ['schema' => $schema]]];
        $input    = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $name . 'Input']]]];
        $paths    = [];

        $listParams = [
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => $this->config->maxPerPage]],
            $d['cursor'] ? ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string']] : ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'sort', 'in' => 'query', 'description' => 'Comma-separated; prefix with - for descending. Fields: ' . implode(', ', $d['sortable']), 'schema' => ['type' => 'string']],
            ['name' => 'fields', 'in' => 'query', 'description' => 'Sparse fields: ' . implode(', ', array_keys($d['output'])), 'schema' => ['type' => 'string']],
        ];

        if ($d['search']) {
            $listParams[] = ['name' => 'q', 'in' => 'query', 'description' => 'Search', 'schema' => ['type' => 'string']];
        }

        if ($d['includes'] !== []) {
            $listParams[] = ['name' => 'include', 'in' => 'query', 'description' => 'Relations: ' . implode(', ', $d['includes']), 'schema' => ['type' => 'string']];
        }

        if ($d['locales'] !== []) {
            $listParams[] = ['name' => 'lang', 'in' => 'query', 'description' => 'Language of translated fields (' . implode(', ', $d['locales']) . ') or * for all', 'schema' => ['type' => 'string']];
        }

        if ($d['softDeletes']) {
            $listParams[] = ['name' => 'trashed', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['with', 'only']]];
        }

        foreach ($d['filters'] as $field) {
            $listParams[] = ['name' => "filter[{$field}]", 'in' => 'query', 'description' => 'Value, or filter[' . $field . '][op] with op: eq, ne, lt, lte, gt, gte, in, between, like, null, notnull', 'schema' => ['type' => 'string']];
        }

        if (! in_array('list', $disabled, true)) {
            $paths[$base]['get'] = ['tags' => [$tag], 'summary' => 'List ' . $tag, 'operationId' => $name . 'List', 'parameters' => $listParams,
                'responses' => ['200' => ['description' => 'OK'] + $json(['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $ref], 'meta' => ['type' => 'object'], 'links' => ['type' => 'object']]])] + $errors([400, 401, 403, 429])] + $security;
        }

        if (! in_array('add', $disabled, true)) {
            $paths[$base]['post'] = ['tags' => [$tag], 'summary' => 'Create', 'operationId' => $name . 'Create', 'requestBody' => $input,
                'responses' => ['201' => ['description' => 'Created'] + $json($item)] + $errors([400, 401, 403, 415, 422, 429])] + $security;
        }

        $one = $base . '/{id}';

        if (! in_array('show', $disabled, true)) {
            $paths[$one]['get'] = ['tags' => [$tag], 'summary' => 'Details', 'operationId' => $name . 'Show', 'parameters' => [$idParam],
                'responses' => ['200' => ['description' => 'OK'] + $json($item), '304' => ['description' => 'Not modified']] + $errors([401, 403, 404])] + $security;
        }

        if (! in_array('edit', $disabled, true)) {
            $paths[$one]['patch'] = ['tags' => [$tag], 'summary' => 'Update (partial)', 'operationId' => $name . 'Update', 'parameters' => [$idParam, ['name' => 'If-Match', 'in' => 'header', 'schema' => ['type' => 'string']]],
                'requestBody' => $input, 'responses' => ['200' => ['description' => 'OK'] + $json($item)] + $errors([401, 403, 404, 412, 422])] + $security;
        }

        if (! in_array('delete', $disabled, true)) {
            $paths[$one]['delete'] = ['tags' => [$tag], 'summary' => $d['softDeletes'] ? 'Move to the trash (?purge=1 deletes permanently)' : 'Delete', 'operationId' => $name . 'Delete',
                'parameters' => [$idParam], 'responses' => ['204' => ['description' => 'Deleted']] + $errors([401, 403, 404, 409, 412])] + $security;
        }

        if ($d['softDeletes'] && ! in_array('restore', $disabled, true)) {
            $paths[$one . '/restore']['post'] = ['tags' => [$tag], 'summary' => 'Restore from the trash', 'operationId' => $name . 'Restore', 'parameters' => [$idParam],
                'responses' => ['200' => ['description' => 'OK'] + $json($item)] + $errors([401, 403, 404])] + $security;
        }

        foreach ($d['operations'] as $key => $operation) {
            $url = $operation['row'] ? $one . '/' . $key : $base . '/' . $key;
            $paths[$url][strtolower($operation['method'])] = array_filter([
                'tags' => [$tag], 'summary' => $operation['description'] !== '' ? $operation['description'] : ucfirst($key), 'operationId' => $name . ucfirst(str_replace(['-', '_'], '', $key)),
                'parameters' => $operation['row'] ? [$idParam] : null,
                'responses'  => ['200' => ['description' => 'OK'], '204' => ['description' => 'Done']] + $errors([401, 403, 404, 409]),
            ]) + $security;
        }

        if (! in_array('bulk', $disabled, true) && $d['bulk'] !== []) {
            $paths[$base . '/bulk']['post'] = ['tags' => [$tag], 'summary' => 'Bulk action', 'operationId' => $name . 'Bulk',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['action', 'ids'], 'properties' => [
                    'action' => ['type' => 'string', 'enum' => $d['bulk']], 'ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                ]]]]],
                'responses' => ['200' => ['description' => 'Processed and skipped ids']] + $errors([401, 403, 422])] + $security;
        }

        return $paths;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function authPaths(string $prefix): array
    {
        $base   = '/' . trim($prefix, '/');
        $json   = static fn (array $properties, array $required = []): array => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => $properties] + ($required === [] ? [] : ['required' => $required])]]];
        $public = ['security' => [[]]];
        $tag    = ['tags' => ['Authentication']];

        return [
            $base . '/auth/token' => [
                'post' => $tag + ['summary' => 'Sign in (step 1): returns a two-factor challenge', 'operationId' => 'authToken',
                    'requestBody' => $json(['email' => ['type' => 'string', 'format' => 'email'], 'password' => ['type' => 'string', 'format' => 'password'], 'device_name' => ['type' => 'string'], 'scopes' => ['type' => 'array', 'items' => ['type' => 'string']]], ['email', 'password']),
                    'responses' => ['202' => ['description' => 'Challenge: method email, totp or web (code shown in the web profile)'], '401' => ['$ref' => '#/components/responses/E401'], '429' => ['$ref' => '#/components/responses/E429']]] + $public,
                'delete' => $tag + ['summary' => 'Sign out (revokes the current token)', 'operationId' => 'authLogout', 'responses' => ['204' => ['description' => 'Signed out']]],
            ],
            $base . '/auth/token/verify' => [
                'post' => $tag + ['summary' => 'Sign in (step 2): the code of the challenge', 'operationId' => 'authVerify',
                    'requestBody' => $json(['challenge' => ['type' => 'string'], 'code' => ['type' => 'string']], ['challenge', 'code']),
                    'responses' => ['201' => ['description' => 'Token'], '422' => ['$ref' => '#/components/responses/E422']]] + $public,
            ],
            $base . '/auth/token/refresh' => [
                'post' => $tag + ['summary' => 'Replace the current token with a new one', 'operationId' => 'authRefresh', 'responses' => ['201' => ['description' => 'Token']]],
            ],
            $base . '/me/tokens' => [
                'get' => $tag + ['summary' => 'Tokens of the current user', 'operationId' => 'meTokens', 'responses' => ['200' => ['description' => 'OK']]],
            ],
            $base . '/me/tokens/{id}' => [
                'delete' => $tag + ['summary' => 'Revoke a token', 'operationId' => 'meTokenDelete', 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], 'responses' => ['204' => ['description' => 'Revoked']]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function problemSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'type'   => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'],
            'detail' => ['type' => 'string'], 'code' => ['type' => 'string'],
            'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function commonResponses(): array
    {
        $titles = [400 => 'Bad request', 401 => 'Authentication required', 403 => 'Not allowed', 404 => 'Not found', 409 => 'Conflict',
            412 => 'The resource has changed', 415 => 'Unsupported content type', 422 => 'Invalid data', 429 => 'Too many requests'];
        $result = [];

        foreach ($titles as $code => $title) {
            $result['E' . $code] = ['description' => $title, 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
        }

        return $result;
    }

    protected function openApiPath(string $path): string
    {
        $count = 0;

        // Grups de CodeIgniter ((:num), (:segment)...) => {p1}, {p2}...
        return (string) preg_replace_callback('/\([^)]*\)/', static function () use (&$count): string {
            return '{p' . (++$count) . '}';
        }, trim($path, '/'));
    }

    protected function schemaName(string $resource): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $resource)));
    }

    protected function collection(): RouteCollection
    {
        return $this->routes ??= service('routes')->loadRoutes();
    }

    /**
     * Converteix el document a YAML (sense dependències).
     *
     * @param array<array-key, mixed> $data
     */
    public static function toYaml(array $data, int $indent = 0): string
    {
        $out = '';
        $pad = str_repeat('  ', $indent);

        foreach ($data as $key => $value) {
            $prefix = array_is_list($data) ? $pad . '- ' : $pad . self::yamlScalar((string) $key) . ': ';

            if (is_array($value) && $value !== []) {
                $out .= rtrim($prefix) . "\n" . self::toYaml($value, $indent + 1);
            } elseif ($value instanceof \stdClass || $value === []) {
                $out .= $prefix . ($value === [] && array_is_list($value) ? '[]' : '{}') . "\n";
            } else {
                $out .= $prefix . self::yamlScalar($value) . "\n";
            }
        }

        return $out;
    }

    protected static function yamlScalar(mixed $value): string
    {
        return match (true) {
            $value === null  => 'null',
            is_bool($value)  => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default          => (string) json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
