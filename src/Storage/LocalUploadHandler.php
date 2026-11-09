<?php

declare(strict_types=1);

namespace Kpapy\Storage;

use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use Kpapy\Config\Kpapy as KpapyConfig;
use Kpapy\Http\ApiException;

/**
 * Gestor per defecte: desa els fitxers en un directori local.
 *
 * Opcions del camp: 'path' (directori; per defecte Config\Kpapy::$uploadPath/<taula>),
 * 'subdir' (text amb {camp} o fn (array $data): string), 'url' (URL base pública; sense ella
 * els fitxers són privats).
 */
class LocalUploadHandler implements UploadHandler, UploadReader
{
    public function store(UploadedFile $file, string $field, array $context): string
    {
        $options = (array) ($context['options'] ?? []);
        $data    = (array) ($context['record'] ?? []);
        $subdir  = $options['subdir'] ?? '';

        if ($subdir instanceof Closure) {
            $subdir = (string) $subdir($data);
        }

        $subdir = trim(preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) ($data[$m[1]] ?? ''), (string) $subdir) ?? '', '/');

        if ($subdir !== '' && ! preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $subdir)) {
            $subdir = '';
        }

        $dir = $this->directory($context) . ($subdir === '' ? '' : '/' . $subdir);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new ApiException(500, 'server-error', lang('Kpapy.exceptions.cannotCreateDir', [$dir]));
        }

        $name = $file->getRandomName();
        $file->move($dir, $name);

        return ($subdir === '' ? '' : $subdir . '/') . $name;
    }

    public function delete(string $stored, string $field, array $context): void
    {
        $file = $this->path($stored, $context);

        if ($file !== null) {
            @unlink($file);
        }
    }

    public function url(string $stored, string $field, array $context): ?string
    {
        $options = (array) ($context['options'] ?? []);

        if (! isset($options['url'])) {
            return null;
        }

        return rtrim((string) $options['url'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $stored)));
    }

    public function read(string $stored, string $field, array $context): ?string
    {
        $file = $this->path($stored, $context);

        return $file === null ? null : (string) file_get_contents($file);
    }

    /**
     * Directori del camp.
     *
     * @param array<string, mixed> $context
     */
    public function directory(array $context): string
    {
        $options = (array) ($context['options'] ?? []);
        $config  = $context['config'] ?? null;
        $config  = $config instanceof KpapyConfig ? $config : new KpapyConfig();

        return rtrim((string) ($options['path'] ?? $config->uploadPath . '/' . ($context['table'] ?? '')), '/\\');
    }

    /**
     * Camí absolut d'un fitxer desat, només si és dins del directori del camp.
     *
     * @param array<string, mixed> $context
     */
    protected function path(string $stored, array $context): ?string
    {
        if ($stored === '' || str_contains($stored, '..')) {
            return null;
        }

        $root = realpath($this->directory($context));
        $file = realpath($this->directory($context) . '/' . $stored);

        return $root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }
}
