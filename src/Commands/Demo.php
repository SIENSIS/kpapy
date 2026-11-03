<?php

declare(strict_types=1);

namespace Kpapy\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Kpapy\Demo\Demo as DemoModule;
use Kpapy\Demo\Installer;
use Throwable;

/**
 * php spark kpapy:demo <install|status|reset|uninstall> [--force] [--yes] [--allow-production]
 */
class Demo extends BaseCommand
{
    protected $group       = 'Kpapy';
    protected $name        = 'kpapy:demo';
    protected $description = 'Installs, resets or removes the kpapy demo (a small library API with a web console).';
    protected $usage       = 'kpapy:demo <install|status|reset|uninstall> [options]';
    protected $arguments   = ['action' => 'install, status, reset or uninstall'];
    protected $options     = [
        '--force'            => 'install: remove the tables of a previous installation first',
        '--yes'              => 'Do not ask for confirmation (reset, uninstall)',
        '--allow-production' => 'install, reset: run even if ENVIRONMENT is "production" (anyone can get a demo token!)',
    ];

    public function run(array $params)
    {
        $action    = $params[0] ?? 'status';
        $installer = new Installer();

        if (in_array($action, ['install', 'reset'], true) && ENVIRONMENT === 'production' && ! array_key_exists('allow-production', CLI::getOptions())) {
            CLI::error('The demo is not installed in production. Use --allow-production if you really want it.');

            return EXIT_ERROR;
        }

        try {
            switch ($action) {
                case 'install':
                    if (array_key_exists('force', CLI::getOptions())) {
                        DemoModule::isInstalled() ? $installer->uninstall() : null;
                    }

                    $installer->install(array_key_exists('allow-production', CLI::getOptions()));
                    $this->summary('The demo has been installed.');

                    return EXIT_SUCCESS;

                case 'reset':
                    if (! DemoModule::isInstalled()) {
                        CLI::error('The demo is not installed.');

                        return EXIT_ERROR;
                    }

                    if ($this->confirm('All demo data will be generated again. Continue?')) {
                        $installer->reset();
                        CLI::write('The demo data has been reset.', 'green');
                    }

                    return EXIT_SUCCESS;

                case 'uninstall':
                    if ($this->confirm('The demo tables, users and files will be removed. Continue?')) {
                        $installer->uninstall();
                        CLI::write('The demo has been removed.', 'green');
                    }

                    return EXIT_SUCCESS;

                default:
                    if (! DemoModule::isInstalled()) {
                        CLI::write('The demo is not installed: php spark kpapy:demo install', 'yellow');

                        return EXIT_SUCCESS;
                    }

                    $db = db_connect();

                    foreach (DemoModule::TABLES as $table) {
                        CLI::write('  ' . str_pad($table, 30) . ($db->tableExists($table) ? $db->table($table)->countAllResults() . ' rows' : CLI::color('missing', 'red')));
                    }

                    $this->summary('The demo is installed' . (DemoModule::isEnabled() ? '' : ' but disabled in this environment') . '.');

                    return EXIT_SUCCESS;
            }
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }
    }

    private function confirm(string $question): bool
    {
        return array_key_exists('yes', CLI::getOptions()) || CLI::prompt($question, ['n', 'y']) === 'y';
    }

    private function summary(string $title): void
    {
        CLI::write($title, 'green');
        CLI::write('Console: ' . DemoModule::url());
        CLI::write('API:     ' . site_url(DemoModule::API . '/books'));
        CLI::write('Docs:    ' . site_url(DemoModule::API . '/docs'));

        foreach (DemoModule::USERS as [$email, , $password]) {
            CLI::write("User:    {$email} / {$password}");
        }
    }
}
