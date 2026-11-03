<?php

declare(strict_types=1);

namespace Kpapy;

use Kpapy\Config\Kpapy as KpapyConfig;
use RuntimeException;

/**
 * Emmascarador per defecte: AES-256-CTR amb un HMAC de l'id com a vector inicial. És
 * determinista (un id té sempre el mateix codi en un context) i el context (el nom de la
 * taula) fa que l'id d'una taula no serveixi per a una altra.
 */
class DefaultIdMasker implements IdMasker
{
    protected const CIPHER = 'aes-256-ctr';
    protected const TAG    = 12;

    protected ?string $encKey = null;
    protected ?string $macKey = null;

    public function __construct(protected KpapyConfig $config)
    {
    }

    public function encode(string $id, string $context): string
    {
        [$encKey, $macKey] = $this->keys();

        $tag    = $this->tag($id, $context, $macKey);
        $cipher = openssl_encrypt($id, self::CIPHER, $encKey, OPENSSL_RAW_DATA, $tag . "\0\0\0\0");

        if ($cipher === false) {
            throw new RuntimeException(lang('Kpapy.exceptions.maskFailed'));
        }

        return rtrim(strtr(base64_encode($tag . $cipher), '+/', '-_'), '=');
    }

    public function decode(string $code, string $context): ?string
    {
        if ($code === '' || strlen($code) > 400 || ! preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
            return null;
        }

        $raw = base64_decode(strtr($code, '-_', '+/'), true);

        if ($raw === false || strlen($raw) <= self::TAG) {
            return null;
        }

        [$encKey, $macKey] = $this->keys();

        $tag = substr($raw, 0, self::TAG);
        $id  = openssl_decrypt(substr($raw, self::TAG), self::CIPHER, $encKey, OPENSSL_RAW_DATA, $tag . "\0\0\0\0");

        if ($id === false || ! hash_equals($this->tag($id, $context, $macKey), $tag)) {
            return null;
        }

        // Un mateix id només té un codi vàlid
        return $this->encode($id, $context) === $code ? $id : null;
    }

    protected function tag(string $id, string $context, string $macKey): string
    {
        return substr(hash_hmac('sha256', $context . "\0" . $id, $macKey, true), 0, self::TAG);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function keys(): array
    {
        if ($this->encKey === null) {
            if (! extension_loaded('openssl')) {
                throw new RuntimeException(lang('Kpapy.exceptions.opensslRequired'));
            }

            $this->encKey = Secret::key($this->config, 'mask-encrypt');
            $this->macKey = Secret::key($this->config, 'mask-sign');
        }

        return [$this->encKey, $this->macKey];
    }
}
