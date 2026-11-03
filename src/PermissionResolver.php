<?php

declare(strict_types=1);

namespace Kpapy;

/**
 * Decideix els permisos de tots els recursos de l'API des d'un sol lloc.
 *
 * El resolutor té l'última paraula si denega; si permet o no opina (null), les regles
 * de setPermission() encara poden restringir.
 */
interface PermissionResolver
{
    /**
     * @param string                    $resource  Nom del recurs (setResource(); per defecte, la taula)
     * @param string                    $operation list, show, add, edit, delete, trash, restore, purge,
     *                                             search, filters, bulk, o el permís d'una operació pròpia
     * @param array<string, mixed>|null $row       Registre afectat, o null si la pregunta és general
     */
    public function allows(string $resource, string $operation, ?array $row, Resource $api): ?bool;
}
