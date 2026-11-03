<?php

declare(strict_types=1);

namespace Kpapy\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Kpapy\OpenApi\Generator;

/**
 * php spark kpapy:routes: the API resources, their filters and the permissions they ask for.
 */
class Routes extends BaseCommand
{
    protected $group       = 'Kpapy';
    protected $name        = 'kpapy:routes';
    protected $description = 'Lists the API resources (Resource::routes()) with their resource name and permission catalogue.';
    protected $usage       = 'kpapy:routes';

    public function run(array $params)
    {
        $generator = new Generator();
        $rows      = [];

        foreach ($generator->resourceRoutes() as $path => $route) {
            $definition = $generator->describe($route['handler'], $path);

            $rows[] = [
                $path,
                $route['public'] ? 'public' : 'token',
                $definition['resource'] ?? '?',
                $definition === null ? '(error)' : implode(', ', $definition['catalogue']),
            ];
        }

        if ($rows === []) {
            CLI::write('No API resources found (register them with Kpapy\Resource::routes()).', 'yellow');

            return EXIT_SUCCESS;
        }

        CLI::table($rows, ['Path', 'Auth', 'Resource', 'Permissions asked']);

        foreach ($generator->warnings as $warning) {
            CLI::write('Warning: ' . $warning, 'yellow');
        }

        return EXIT_SUCCESS;
    }
}
