<?php

declare(strict_types=1);

namespace Kpapy;

use Kpapy\Config\Kpapy as KpapyConfig;
use RuntimeException;

/**
 * Clau secreta de la llibreria (emmascarar identificadors, signar URL de fitxers).
 *
 * Mateix origen i mateixa derivació que Kpcrudy\Secret, perquè els codis coincideixin:
 * Config\Kpapy::$maskKey, Config\Kpcrudy::$maskKey (si kpcrudy hi és), la clau
 * d'encriptació de CodeIgniter o writable/kpcrudy/mask.key.
 */
final class Secret
{
    public static function key(KpapyConfig $config, string $purpose): string
    {
        // El prefix és el de kpcrudy a propòsit: les claus derivades han de ser les mateixes
        return hash_hmac('sha256', 'kpcrudy-' . $purpose, self::source($config), true);
    }

    private static function source(KpapyConfig $config): string
    {
        $key = $config->maskKey;

        if ($key === '' && class_exists(\Kpcrudy\Config\Kpcrudy::class)) {
            $kpcrudy = config('Kpcrudy');
            $key     = is_object($kpcrudy) && is_string($kpcrudy->maskKey ?? null) ? $kpcrudy->maskKey : '';
        }

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

        return self::fileKey(WRITEPATH . 'kpcrudy' . DIRECTORY_SEPARATOR . 'mask.key');
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
