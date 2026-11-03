<?php

declare(strict_types=1);

namespace Kpapy\Concerns;

/**
 * Descripció del recurs per generar la documentació OpenAPI (Kpapy\OpenApi\Generator).
 *
 * El generador crida el controlador de cada ruta amb Resource::$describing activat:
 * render() no atén cap petició, només desa la descripció a Resource::$described.
 *
 * @mixin \Kpapy\Resource
 */
trait Describes
{
    /**
     * Mode descripció (només el generador de documentació l'activa).
     */
    public static bool $describing = false;

    /**
     * Descripció del darrer recurs renderitzat en mode descripció.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $described = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $this->boot();

        $describe = function (string $field, string $mode): array {
            $type   = $this->typeOf($field);
            $meta   = $this->meta[$field] ?? null;
            $schema = match (true) {
                $field === $this->primaryKey             => ['type' => $this->masksIds() ? 'string' : 'integer'],
                isset($this->relations[$field])          => ['type' => 'string', 'description' => 'Id of ' . $this->relations[$field]['table']],
                $type === 'relation_nn'                  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids of ' . $this->relationsNN[$field]['table']],
                $type === 'int'                          => ['type' => 'integer'],
                $type === 'float'                        => ['type' => 'number'],
                $type === 'decimal'                      => ['type' => 'string', 'format' => 'decimal'],
                $type === 'bool'                         => ['type' => 'boolean'],
                $type === 'date'                         => ['type' => 'string', 'format' => 'date'],
                $type === 'datetime'                     => ['type' => 'string', 'format' => 'date-time'],
                $type === 'email'                        => ['type' => 'string', 'format' => 'email'],
                $type === 'url'                          => ['type' => 'string', 'format' => 'uri'],
                $type === 'json'                         => ['type' => 'object'],
                $type === 'enum'                         => ['type' => 'string', 'enum' => array_map('strval', array_keys((array) ($this->typeOptions($field)['options'] ?? [])))],
                $type === 'multiselect'                  => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_map('strval', array_keys((array) ($this->typeOptions($field)['options'] ?? [])))]],
                in_array($type, ['upload', 'image'], true) => $mode === 'output' ? ['type' => 'string', 'format' => 'uri'] : ['type' => 'string', 'format' => 'binary'],
                $type === 'translation'                  => ['oneOf' => [['type' => 'string'], ['type' => 'object', 'additionalProperties' => ['type' => 'string']]], 'description' => 'Translated: ' . implode(', ', $this->translations['locales'])],
                $type === 'password'                     => ['type' => 'string', 'format' => 'password', 'writeOnly' => true],
                default                                  => ['type' => 'string'],
            };

            if ($meta !== null && $meta['nullable'] && $field !== $this->primaryKey) {
                $schema['nullable'] = true;
            }

            if ($meta !== null && $meta['max_length'] && in_array($type, ['text', 'email', 'url'], true)) {
                $schema['maxLength'] = $meta['max_length'];
            }

            return $schema;
        };

        $output = [];

        foreach ($this->outputFields() as $field) {
            $output[$field] = isset($this->fieldCallbacks[$field]) && ! isset($this->meta[$field]) ? new \stdClass() : $describe($field, 'output');
        }

        $input    = [];
        $required = [];

        foreach ($this->inputFields('add') as $field) {
            $input[$field] = $describe($field, 'input');

            if (in_array('required', $this->fieldRules($field, 'add', null), true)) {
                $required[] = $field;
            }
        }

        $operations = [];

        foreach ($this->operations as $key => $operation) {
            $operations[$key] = ['row' => $operation['row'], 'method' => $operation['method'], 'permission' => $operation['permission'], 'description' => $operation['description']];
        }

        $bulk = array_keys($this->bulkActions);

        foreach (['delete' => 'delete', 'restore' => 'restore', 'purge' => 'purge'] as $action => $permission) {
            if (($this->permissions[$permission] ?? null) !== false && ($action === 'delete' || $this->softDeletes)) {
                $bulk[] = $action;
            }
        }

        return [
            'resource'    => $this->getResource(),
            'table'       => $this->table,
            'summary'     => $this->descriptions[0][0] ?? ucfirst(str_replace(['-', '_'], ' ', $this->getResource())),
            'description' => $this->descriptions[0][1] ?? '',
            'output'      => $output,
            'input'       => $input,
            'required'    => $required,
            'filters'     => $this->filterFieldList(),
            'search'      => $this->searchFieldList() !== [],
            'sortable'    => $this->sortableFields(),
            'includes'    => [...array_column($this->relations, 'as'), ...array_keys($this->relationsNN)],
            'locales'     => $this->translations['locales'] ?? [],
            'softDeletes' => $this->softDeletes,
            'cursor'      => $this->cursor !== null,
            'disabled'    => array_keys(array_filter($this->permissions, static fn ($rule): bool => $rule === false)),
            'operations'  => $operations,
            'bulk'        => array_values(array_unique($bulk)),
            'catalogue'   => $this->permissionCatalogue(),
        ];
    }
}
