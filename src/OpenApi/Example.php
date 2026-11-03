<?php

declare(strict_types=1);

namespace Kpapy\OpenApi;

/**
 * Valors d'exemple a partir d'un esquema OpenAPI, per omplir el cos de les peticions a
 * l'opció "Prova-ho" de la pàgina de documentació.
 */
final class Example
{
    /**
     * @param array<string, mixed>                $schema
     * @param array<string, array<string, mixed>> $schemas components.schemas del document
     */
    public static function fromSchema(array $schema, array $schemas = [], int $depth = 0): mixed
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $name = substr($schema['$ref'], strrpos($schema['$ref'], '/') + 1);

            return $depth > 4 || ! isset($schemas[$name]) ? null : self::fromSchema($schemas[$name], $schemas, $depth + 1);
        }

        if (isset($schema['oneOf'][0]) && is_array($schema['oneOf'][0])) {
            return self::fromSchema($schema['oneOf'][0], $schemas, $depth + 1);
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        return match ($schema['type'] ?? 'string') {
            'object'  => self::object($schema, $schemas, $depth),
            'array'   => isset($schema['items']['enum'][0]) ? [$schema['items']['enum'][0]] : [],
            'integer' => 0,
            'number'  => 0,
            'boolean' => false,
            default   => match ($schema['format'] ?? '') {
                'email'     => 'user@example.com',
                'date'      => date('Y-m-d'),
                'date-time' => date('c'),
                'uri'       => 'https://example.com',
                'decimal'   => '0.00',
                default     => '',
            },
        };
    }

    /**
     * Un objecte amb totes les propietats que es poden enviar en JSON (sense fitxers).
     *
     * @param array<string, mixed>                $schema
     * @param array<string, array<string, mixed>> $schemas
     *
     * @return array<string, mixed>|object
     */
    private static function object(array $schema, array $schemas, int $depth): array|object
    {
        $object = [];

        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            if (! is_array($property) || ($property['format'] ?? null) === 'binary' || ($property['readOnly'] ?? false)) {
                continue;
            }

            $object[$name] = self::fromSchema($property, $schemas, $depth + 1);
        }

        return $object === [] ? new \stdClass() : $object;
    }
}
