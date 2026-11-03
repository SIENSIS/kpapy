<?php

declare(strict_types=1);

namespace Kpapy\Bridge;

use Kpapy\PermissionResolver;
use Kpapy\Resource;

/**
 * Fa servir a l'API un resolutor de kpcrudy que ja existeix. El resolutor de kpcrudy
 * rep un Kpcrudy\Crud: aquí se li passa un CRUD buit amb el mateix recurs, que només
 * serveix perquè el resolutor en llegeixi getResource().
 *
 *     // app/Libraries/ApiPermissions.php
 *     class ApiPermissions extends KpcrudyResolver
 *     {
 *         public function __construct()
 *         {
 *             parent::__construct(new CrudPermissions());   // el resolutor de kpcrudy
 *         }
 *     }
 *
 *     // app/Config/Kpapy.php
 *     public ?string $permissionResolver = \App\Libraries\ApiPermissions::class;
 *
 * Si la classe del projecte pot implementar totes dues interfícies, és més senzill:
 * class CrudPermissions implements \Kpcrudy\PermissionResolver, \Kpapy\PermissionResolver.
 */
class KpcrudyResolver implements PermissionResolver
{
    public function __construct(protected ?object $inner = null)
    {
    }

    public function allows(string $resource, string $operation, ?array $row, Resource $api): ?bool
    {
        if ($this->inner === null || ! class_exists(\Kpcrudy\Crud::class)) {
            return null;
        }

        $crud = (new \Kpcrudy\Crud())->setResource($resource);

        return $this->inner->allows($resource, $operation, $row, $crud);
    }
}
