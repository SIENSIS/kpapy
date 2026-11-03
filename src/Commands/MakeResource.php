<?php

declare(strict_types=1);

namespace Kpapy\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Genera el mètode de controlador i la ruta d'un recurs de l'API a partir d'una taula.
 *
 *     php spark kpapy:make books
 *     php spark kpapy:make posts --namespace "Modules\Blog" --route api/v1/posts --resource blog-posts --as api-posts
 */
class MakeResource extends BaseCommand
{
    protected $group       = 'Kpapy';
    protected $name        = 'kpapy:make';
    protected $description = 'Generates the controller method and the routes of an API resource from a database table.';
    protected $usage       = 'kpapy:make <table> [options]';
    protected $arguments   = ['table' => 'Database table'];
    protected $options     = [
        '--namespace'  => 'Base namespace of the app or module (default: App)',
        '--path'       => 'Directory of the base namespace (default: from the autoloader)',
        '--routes'     => 'Routes file (default: <path>/Config/Routes.php, created if missing)',
        '--controller' => 'Controller, relative to <ns>\Controllers (default: Api/<Table>)',
        '--method'     => 'Controller method (default: index)',
        '--route'      => 'Route path (default: api/v1/<table>)',
        '--resource'   => 'Resource name for the permissions (default: the table)',
        '--as'         => 'Route name',
        '--filter'     => 'Route filter (default: kpapy)',
        '--extends'    => 'Parent class of a new controller (default: <ns>\Controllers\BaseController, App\Controllers\BaseController or CodeIgniter\Controller)',
        '--dry-run'    => 'Show the code without writing any file',
        '--force'      => 'Replace the method if it already exists',
    ];

    public function run(array $params)
    {
        $table = $params[0] ?? '';
        $db    = db_connect();

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || ! $db->tableExists($table)) {
            CLI::error("The table \"{$table}\" does not exist.");

            return EXIT_ERROR;
        }

        $ns         = trim((string) (CLI::getOption('namespace') ?: 'App'), '\\');
        $base       = $this->basePath($ns);
        $studly     = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $table)));
        $controller = trim(str_replace('\\', '/', (string) (CLI::getOption('controller') ?: 'Api/' . $studly)), '/');
        $method     = (string) (CLI::getOption('method') ?: 'index');
        $route      = trim((string) (CLI::getOption('route') ?: 'api/v1/' . str_replace('_', '-', strtolower($table))), '/');
        $resource   = (string) (CLI::getOption('resource') ?: $table);
        $filter     = (string) (CLI::getOption('filter') ?: 'kpapy');
        $as         = (string) (CLI::getOption('as') ?: '');

        if ($base === null || ! preg_match('#^[A-Z][A-Za-z0-9_]*(/[A-Z][A-Za-z0-9_]*)*$#', $controller) || ! preg_match('/^[a-z][A-Za-z0-9_]*$/', $method)) {
            CLI::error('Invalid namespace, controller or method.');

            return EXIT_ERROR;
        }

        $segments  = explode('/', $controller);
        $class     = array_pop($segments);
        $namespace = rtrim($ns . '\\Controllers\\' . implode('\\', $segments), '\\');
        $file      = $base . 'Controllers/' . $controller . '.php';
        $handler   = $ns === 'App' ? str_replace('/', '\\', $controller) . '::' . $method : '\\' . $namespace . '\\' . $class . '::' . $method;
        $routes    = (string) (CLI::getOption('routes') ?: $base . 'Config/Routes.php');

        $methodCode = $this->methodCode($table, $resource, $method);
        $options    = "['filter' => " . var_export($filter, true) . ($as !== '' ? ", 'as' => " . var_export($as, true) : '') . ']';
        $routeLine  = '\Kpapy\Resource::routes($routes, ' . var_export($route, true) . ', ' . var_export($handler, true) . ', ' . $options . ');';

        if (array_key_exists('dry-run', CLI::getOptions())) {
            CLI::write("--- {$namespace}\\{$class} ---", 'yellow');
            CLI::write($methodCode);
            CLI::write("--- {$routes} ---", 'yellow');
            CLI::write($routeLine);

            return EXIT_SUCCESS;
        }

        try {
            $status = $this->writeController($file, $namespace, $class, $method, $methodCode, $ns);
            $added  = $this->writeRoute($routes, $route, $routeLine);
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write("Controller: {$file} ({$status} {$method}())", 'green');
        CLI::write($added ? "Route:      {$routeLine}" : "Route:      \"{$route}\" was already in {$routes}", $added ? 'green' : 'yellow');
        CLI::write('Try it: GET ' . site_url($route) . ' with a token, and declare the resource "' . $resource . '" in your permissions.');

        return EXIT_SUCCESS;
    }

    protected function methodCode(string $table, string $resource, string $method): string
    {
        $db        = db_connect();
        $fields    = $db->getFieldData($table);
        $foreign   = [];
        $filters   = [];
        $search    = [];
        $relations = '';

        try {
            foreach ($db->getForeignKeyData($table) as $key) {
                $column = is_array($key->column_name ?? null) ? $key->column_name[0] : ($key->column_name ?? null);
                $other  = $key->foreign_table_name ?? null;

                if (is_string($column) && is_string($other)) {
                    $foreign[$column] = $other;
                }
            }
        } catch (Throwable) {
            // Sense informació de claus foranes: no es generen relacions
        }

        foreach ($fields as $field) {
            $name = $field->name;
            $type = strtolower((string) $field->type);

            if (in_array($name, ['created_at', 'updated_at', 'deleted_at'], true) || ($field->primary_key ?? false)) {
                continue;
            }

            if (isset($foreign[$name])) {
                $display    = $this->displayColumn($foreign[$name]);
                $relations .= "            ->setRelation('{$name}', '{$foreign[$name]}', ['{$display}'])\n";
                $filters[]  = $name;
            } elseif (in_array($type, ['enum', 'tinyint', 'bool', 'boolean', 'date', 'datetime'], true)) {
                $filters[] = $name;
            } elseif (in_array($type, ['varchar', 'char', 'text', 'tinytext', 'mediumtext'], true) && ! preg_match('/pass|token|secret|hash/', $name)) {
                $search[] = $name;
            }
        }

        $list = static fn (array $values): string => "['" . implode("', '", $values) . "']";

        return "    /**\n"
            . "     * API resource for the \"{$table}\" table (generated by \"php spark kpapy:make\").\n"
            . "     */\n"
            . "    public function {$method}(): ResponseInterface\n"
            . "    {\n"
            . "        return (new Resource())\n"
            . "            ->setTable('{$table}')\n"
            . "            ->setResource('{$resource}')\n"
            . $relations
            . ($filters === [] ? '' : "            ->filters({$list($filters)})\n")
            . ($search === [] ? '' : "            ->searchFields({$list(array_slice($search, 0, 4))})\n")
            . "            ->render();\n"
            . "    }\n";
    }

    protected function displayColumn(string $table): string
    {
        foreach (db_connect()->getFieldData($table) as $field) {
            if (in_array(strtolower((string) $field->type), ['varchar', 'char'], true)) {
                return $field->name;
            }
        }

        return 'id';
    }

    protected function writeController(string $file, string $namespace, string $class, string $method, string $code, string $ns): string
    {
        if (! is_file($file)) {
            $parent = (string) (CLI::getOption('extends') ?: (class_exists($ns . '\Controllers\BaseController') ? $ns . '\Controllers\BaseController' : (class_exists('App\Controllers\BaseController') ? 'App\Controllers\BaseController' : 'CodeIgniter\Controller')));
            $short  = substr($parent, (int) strrpos($parent, '\\') + 1);
            $uses   = array_unique(array_filter([
                substr($parent, 0, (int) strrpos($parent, '\\')) === $namespace ? null : $parent,
                'CodeIgniter\HTTP\ResponseInterface',
                'Kpapy\Resource',
            ]));
            sort($uses);

            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }

            file_put_contents($file, "<?php\n\nnamespace {$namespace};\n\n" . implode('', array_map(static fn ($u) => "use {$u};\n", $uses)) . "\nclass {$class} extends {$short}\n{\n{$code}}\n");

            return 'created';
        }

        $source = (string) file_get_contents($file);
        $exists = preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $match, PREG_OFFSET_CAPTURE) === 1;

        if ($exists && ! array_key_exists('force', CLI::getOptions())) {
            throw new \RuntimeException("The method {$method}() already exists in {$class}. Use --force to replace it.");
        }

        if ($exists) {
            // Del docblock (o de la declaració) fins a la clau que tanca el mètode
            $start = strrpos(substr($source, 0, $match[0][1]), '/**') ?: $match[0][1];
            $start = strrpos(substr($source, 0, $start), "\n") + 1;
            $open  = strpos($source, '{', $match[0][1]);
            $depth = 0;

            for ($i = $open; $i < strlen($source); $i++) {
                $depth += $source[$i] === '{' ? 1 : ($source[$i] === '}' ? -1 : 0);

                if ($depth === 0) {
                    break;
                }
            }

            $source = substr($source, 0, $start) . $code . substr($source, $i + 2);
            $status = 'replaced';
        } else {
            $end    = strrpos($source, '}');
            $source = rtrim(substr($source, 0, $end)) . "\n\n" . $code . "}\n";
            $status = 'added';
        }

        foreach (['CodeIgniter\HTTP\ResponseInterface', 'Kpapy\Resource'] as $use) {
            if (! str_contains($source, "use {$use};")) {
                $source = preg_replace('/^namespace [^;]+;\n/m', "$0\nuse {$use};", $source, 1) ?? $source;
            }
        }

        file_put_contents($file, $source);

        return $status;
    }

    protected function writeRoute(string $file, string $route, string $line): bool
    {
        $source = is_file($file) ? (string) file_get_contents($file) : "<?php\n\n/**\n * @var \\CodeIgniter\\Router\\RouteCollection \$routes\n */\n";

        if (preg_match('/Resource::routes\(\s*\$routes\s*,\s*[\'"]\/?' . preg_quote($route, '/') . '[\'"]/', $source)) {
            return false;
        }

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }

        file_put_contents($file, rtrim($source) . "\n\n" . $line . "\n");

        return true;
    }

    protected function basePath(string $namespace): ?string
    {
        $path = CLI::getOption('path');

        if (is_string($path) && $path !== '') {
            return rtrim(preg_match('#^([A-Za-z]:)?[/\\\\]#', $path) ? $path : ROOTPATH . $path, '/\\') . '/';
        }

        if ($namespace === 'App') {
            return APPPATH;
        }

        $registered = service('autoloader')->getNamespace();
        $parts      = explode('\\', $namespace);

        for ($i = count($parts); $i > 0; $i--) {
            $prefix = implode('\\', array_slice($parts, 0, $i));

            if (isset($registered[$prefix][0])) {
                return rtrim($registered[$prefix][0], '/\\') . '/' . implode('/', array_slice($parts, $i)) . ($i < count($parts) ? '/' : '');
            }
        }

        return null;
    }
}
