<?php

declare(strict_types=1);

namespace Kpapy\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark kpapy:publish config | lang <locale>
 */
class Publish extends BaseCommand
{
    protected $group       = 'Kpapy';
    protected $name        = 'kpapy:publish';
    protected $description = 'Copies the kpapy configuration or a language file into the project to customise them.';
    protected $usage       = 'kpapy:publish <config|lang> [locale] [--force]';
    protected $arguments   = ['what' => 'config or lang', 'locale' => 'lang: ca, es, en, fr, pt or de'];
    protected $options     = ['--force' => 'Overwrite the file if it exists'];

    public function run(array $params)
    {
        $what  = $params[0] ?? '';
        $force = array_key_exists('force', CLI::getOptions());

        if ($what === 'config') {
            $target = APPPATH . 'Config/Kpapy.php';
            $source = (string) file_get_contents(dirname(__DIR__) . '/Config/Kpapy.php');
            $body   = substr($source, (int) strpos($source, '{') + 1);
            $code   = "<?php\n\nnamespace Config;\n\n/**\n * kpapy settings of this project. Delete the options you do not change: they take the\n * library's value.\n */\nclass Kpapy extends \\Kpapy\\Config\\Kpapy\n{" . $body;

            return $this->write($target, $code, $force);
        }

        if ($what === 'lang') {
            $locale = $params[1] ?? '';
            $source = dirname(__DIR__) . '/Language/' . $locale . '/Kpapy.php';

            if (! preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) || ! is_file($source)) {
                CLI::error('Usage: php spark kpapy:publish lang <ca|es|en|fr|pt|de>');

                return EXIT_ERROR;
            }

            return $this->write(APPPATH . 'Language/' . $locale . '/Kpapy.php', (string) file_get_contents($source), $force);
        }

        CLI::error('Usage: php spark kpapy:publish <config|lang> [locale] [--force]');

        return EXIT_ERROR;
    }

    private function write(string $file, string $code, bool $force): int
    {
        if (is_file($file) && ! $force) {
            CLI::error(str_replace(ROOTPATH, '', $file) . ' already exists. Use --force to overwrite it.');

            return EXIT_ERROR;
        }

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }

        file_put_contents($file, $code);
        CLI::write('Written ' . str_replace(ROOTPATH, '', $file), 'green');

        return EXIT_SUCCESS;
    }
}
