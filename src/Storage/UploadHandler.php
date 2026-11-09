<?php

declare(strict_types=1);

namespace Kpapy\Storage;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Desa i esborra els fitxers dels camps 'upload' i 'image'.
 *
 * Per defecte és LocalUploadHandler (el directori del camp). Es configura per a tots els
 * recursos a Config\Kpapy::$uploadHandler o per camp amb l'opció 'handler' de fieldType().
 *
 * $context conté:
 *  - 'resource' (string)  nom del recurs (setResource())
 *  - 'table'    (string)  taula del recurs
 *  - 'id'       (string|null) clau primària del registre (null en una alta: encara no existeix)
 *  - 'record'   (array)   el registre: valors antics + valors nous en store(), la fila en delete() i url()
 *  - 'options'  (array)   opcions del camp a fieldType()
 *  - 'type'     (string)  'upload' o 'image'
 *  - 'config'   (Kpapy\Config\Kpapy) configuració del recurs
 *  - 'api'      (Kpapy\Resource) el recurs
 *
 * La mateixa interfície (nom i semàntica) existeix a kpcrudy.
 */
interface UploadHandler
{
    /**
     * Desa el fitxer (ja validat) i retorna el valor que es guarda a la columna del camp
     * (un camí relatiu, una clau...).
     *
     * @param array<string, mixed> $context
     */
    public function store(UploadedFile $file, string $field, array $context): string;

    /**
     * Esborra un fitxer desat abans (fitxer substituït, registre esborrat o error en desar).
     * No ha de llançar cap excepció si el fitxer ja no existeix.
     *
     * @param array<string, mixed> $context
     */
    public function delete(string $stored, string $field, array $context): void;

    /**
     * URL pública del fitxer, o null si no en té (fitxer privat: kpapy dona una URL signada
     * i temporal si el gestor implementa UploadReader).
     *
     * @param array<string, mixed> $context
     */
    public function url(string $stored, string $field, array $context): ?string;
}
