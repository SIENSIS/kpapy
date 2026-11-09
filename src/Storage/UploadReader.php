<?php

declare(strict_types=1);

namespace Kpapy\Storage;

/**
 * Opcional: un UploadHandler que el pot llegir permet que kpapy serveixi els fitxers privats
 * amb URL signades (GET /{id}/files/{field}?expires=…&signature=…).
 */
interface UploadReader
{
    /**
     * Contingut del fitxer, o null si no existeix.
     *
     * @param array<string, mixed> $context El mateix que UploadHandler
     */
    public function read(string $stored, string $field, array $context): ?string;
}
