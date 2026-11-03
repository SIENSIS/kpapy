<?php

declare(strict_types=1);

namespace Kpapy\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Kpapy\OpenApi\Generator;

/**
 * php spark kpapy:openapi [--output file] [--format json|yaml] [--prefix api/v1]
 */
class OpenApi extends BaseCommand
{
    protected $group       = 'Kpapy';
    protected $name        = 'kpapy:openapi';
    protected $description = 'Builds the OpenAPI 3.1 document of the API routes.';
    protected $usage       = 'kpapy:openapi [options]';
    protected $options     = [
        '--output' => 'File to write (e.g. public/openapi.json). Without it, the document is printed.',
        '--format' => 'json (default) or yaml',
        '--prefix' => 'Only routes starting with this path (e.g. api/v1)',
    ];

    public function run(array $params)
    {
        $generator = new Generator();
        $spec      = $generator->generate(CLI::getOption('prefix') ?: null);
        $format    = strtolower((string) (CLI::getOption('format') ?: 'json'));
        $output    = $format === 'yaml'
            ? Generator::toYaml($spec)
            : (string) json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        foreach ($generator->warnings as $warning) {
            CLI::write('Warning: ' . $warning, 'yellow');
        }

        $file = CLI::getOption('output');

        if (! is_string($file) || $file === '') {
            CLI::write($output);

            return EXIT_SUCCESS;
        }

        $path = preg_match('#^([A-Za-z]:)?[/\\\\]#', $file) ? $file : ROOTPATH . $file;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $output);
        CLI::write('Written ' . $file . ' (' . count($spec['paths']) . ' paths).', 'green');

        return EXIT_SUCCESS;
    }
}
