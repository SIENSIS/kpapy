<?php

declare(strict_types=1);

namespace Kpapy\Config;

use Kpapy\Filters\ApiFilter;

/**
 * Registra el filtre 'kpapy' al projecte (descobriment de mòduls de CodeIgniter), de
 * manera que no cal afegir-lo a app/Config/Filters.php.
 */
class Registrar
{
    /**
     * @return array<string, mixed>
     */
    public static function Filters(): array
    {
        return ['aliases' => ['kpapy' => ApiFilter::class]];
    }
}
