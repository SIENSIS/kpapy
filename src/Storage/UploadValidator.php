<?php

declare(strict_types=1);

namespace Kpapy\Storage;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Opcional: un UploadHandler que implementa aquesta interfície substitueix la validació de
 * kpapy (extensió, tipus MIME de les imatges i mida) per la seva.
 */
interface UploadValidator
{
    /**
     * Missatge d'error per a l'usuari, o null si el fitxer és vàlid.
     *
     * @param array<string, mixed> $context El mateix que UploadHandler (sense 'id' en una alta)
     */
    public function validate(UploadedFile $file, string $field, array $context): ?string;
}
