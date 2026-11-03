<?php

declare(strict_types=1);

namespace Kpapy;

use Kpapy\Config\Kpapy as KpapyConfig;
use RuntimeException;

/**
 * Clau secreta de la llibreria (emmascarar identificadors, signar URL de fitxers).
 *
 * Origen: Config\Kpapy::$maskKey, la clau d'encriptació de CodeIgniter o, si no n'hi ha
 * cap, writable/kpapy/mask.key (es crea la primera vegada). Cada ús en deriva una clau pròpia.
 */
final class Secret
{
    public static function key(KpapyConfig $config, string $purpose): string
    {
        return hash_hmac('sha256', 'kpapy-' . $purpose, self::source($config), true);
    }

    private static function source(KpapyConfig $config): string
    {
        $key = $config->maskKey;

        if ($key !== '') {
            if (strlen($key) < 32) {
                throw new RuntimeException(lang('Kpapy.exceptions.maskKeyTooShort'));
            }

            return $key;
        }

        $encryption = config('Encryption');

        if ($encryption !== null && is_string($encryption->key) && $encryption->key !== '') {
            return $encryption->key;
        }

        return self::fileKey(WRITEPATH . 'kpapy' . DIRECTORY_SEPARATOR . 'mask.key');
    }

    private static function fileKey(string $file): string
    {
        if (! is_file($file)) {
            $dir = dirname($file);

            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException(lang('Kpapy.exceptions.cannotCreateDir', [$dir]));
            }

            $handle = @fopen($file, 'xb');

            if ($handle !== false) {
                fwrite($handle, bin2hex(random_bytes(32)));
                fclose($handle);
                @chmod($file, 0600);
            }
        }

        for ($try = 0; strlen($key = trim((string) @file_get_contents($file))) !== 64 && $try < 3; $try++) {
            usleep(50000);
        }

        if (strlen($key) !== 64 || ! ctype_xdigit($key)) {
            throw new RuntimeException(lang('Kpapy.exceptions.invalidKeyFile', [$file]));
        }

        return hex2bin($key);
    }
}
