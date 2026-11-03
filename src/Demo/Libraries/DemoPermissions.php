<?php

namespace Kpapy\Demo\Libraries;

use Kpapy\Auth\Context;
use Kpapy\Demo\Demo;
use Kpapy\PermissionResolver;
use Kpapy\Resource;

/**
 * Central permissions of the demo API (DemoConfig::$permissionResolver, with
 * denyByDefault): administrators can do everything; readers can read and borrow books
 * and return their own loans. In a real project this would ask Shield:
 * auth()->user()->can("{$resource}.{$operation}").
 */
class DemoPermissions implements PermissionResolver
{
    private const READER = [
        'library-books'   => ['list', 'show', 'search', 'filters', 'lend'],
        'library-authors' => ['list', 'show', 'search', 'filters'],
        'library-tags'    => ['list', 'show'],
        'library-loans'   => ['list', 'show', 'filters', 'return'],
    ];

    public function allows(string $resource, string $operation, ?array $row, Resource $api): ?bool
    {
        // Public catalogue: read only, for anyone
        if ($resource === 'library-public') {
            return in_array($operation, ['list', 'show', 'search', 'filters'], true);
        }

        return match (Demo::roleOf(Context::user()?->id)) {
            'admin'  => true,
            'reader' => in_array($operation, self::READER[$resource] ?? [], true),
            default  => false,
        };
    }
}
