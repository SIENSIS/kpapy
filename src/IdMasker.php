<?php

declare(strict_types=1);

namespace Kpapy;

/**
 * Converteix els identificadors reals en codis per a les URL i les respostes, i a l'inrevés.
 * Mateixa interfície que Kpcrudy\IdMasker.
 */
interface IdMasker
{
    public function encode(string $id, string $context): string;

    /**
     * Id real d'un codi, o null si el codi no és vàlid per a aquest context.
     */
    public function decode(string $code, string $context): ?string;
}
