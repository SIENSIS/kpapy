<?php

namespace Tests\Kpapy;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The six languages have the same keys, and every lang('Kpapy.x') of the library exists.
 *
 * @internal
 */
final class LanguageTest extends CIUnitTestCase
{
    public function testEveryLanguageHasTheSameKeys(): void
    {
        $dir  = dirname(__DIR__, 4) . '/src/Language/';
        $keys = static function (array $lines, string $prefix = '') use (&$keys): array {
            $all = [];

            foreach ($lines as $key => $value) {
                $all = [...$all, ...(is_array($value) ? $keys($value, $prefix . $key . '.') : [$prefix . $key])];
            }

            sort($all);

            return $all;
        };

        $english = $keys(require $dir . 'en/Kpapy.php');
        $files   = glob($dir . '*/Kpapy.php');

        $this->assertCount(6, $files);

        foreach ($files as $file) {
            $this->assertSame($english, $keys(require $file), 'Keys of ' . basename(dirname($file)));
        }

        $used = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname($dir), \FilesystemIterator::SKIP_DOTS)) as $source) {
            if ($source->getExtension() === 'php' && preg_match_all("/lang\\('Kpapy\\.([A-Za-z.]+)'/", (string) file_get_contents($source->getPathname()), $m)) {
                $used = [...$used, ...$m[1]];
            }
        }

        // Dynamic keys (lang('Kpapy.problems.' . $type)) end with a dot
        $used = array_filter($used, static fn (string $key): bool => ! str_ends_with($key, '.'));

        $this->assertSame([], array_values(array_diff(array_unique($used), $english)), 'Language keys used but not defined');
    }
}
