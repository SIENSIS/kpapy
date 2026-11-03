<?php

declare(strict_types=1);

namespace Kpapy\Concerns;

use Closure;
use RuntimeException;

/**
 * Configuració dels camps i estructura de la taula.
 *
 * @mixin \Kpapy\Resource
 */
trait Schema
{
    protected bool $booted = false;

    /**
     * Estructura de la taula: camp => [type, max_length, nullable, default, primary].
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $meta = [];

    protected bool $softDeletes      = false;
    protected ?bool $softDeletesSet  = null;
    protected string $deletedField   = 'deleted_at';
    protected ?string $createdField  = null;
    protected ?string $updatedField  = null;

    /** @var list<string>|null */
    protected ?array $fields = null;

    /** @var list<string>|null */
    protected ?array $listFields = null;

    /** @var list<string>|null */
    protected ?array $writeFields = null;

    /** @var list<string>|null */
    protected ?array $addFields = null;

    /** @var list<string>|null */
    protected ?array $editFields = null;

    /** @var list<string> */
    protected array $hidden = [];

    /** @var list<string> */
    protected array $readonly = [];

    /** @var list<string> */
    protected array $required = [];

    /** @var array<string, mixed> */
    protected array $defaults = [];

    /** @var array<string, list<string>> */
    protected array $rules = [];

    /** @var array<string, array<string, string>> */
    protected array $ruleMessages = [];

    /** @var array<string, array{type: string, options: array<string, mixed>}> */
    protected array $types = [];

    /** @var array<string, Closure> */
    protected array $fieldCallbacks = [];

    protected ?Closure $transformer = null;

    /** @var array<string, array<string, mixed>> */
    protected array $relations = [];

    /** @var array<string, array<string, mixed>> */
    protected array $relationsNN = [];

    /** @var array<string, mixed>|null */
    protected ?array $translations = null;

    /** @var list<string>|null */
    protected ?array $filterFields = null;

    /** @var list<string>|null */
    protected ?array $searchFields = null;

    /** @var list<string> */
    protected array $unsortable = [];

    /** @var array{0: string, 1: string}|null */
    protected ?array $defaultOrder = null;

    /** @var array{0: string, 1: string}|null */
    protected ?array $cursor = null;

    protected bool $withTotal = true;
    protected ?int $perPage   = null;

    // ------------------------------------------------------------------
    // Camps
    // ------------------------------------------------------------------

    /**
     * Camps que es retornen (llistat i detall). Poden incloure camps virtuals amb callbackField().
     *
     * @param list<string> $fields
     */
    public function fields(array $fields): static
    {
        $this->fields = array_values($fields);

        return $this;
    }

    /**
     * Camps del llistat quan són diferents dels del detall.
     *
     * @param list<string> $fields
     */
    public function listFields(array $fields): static
    {
        $this->listFields = array_values($fields);

        return $this;
    }

    /**
     * Camps que el client pot enviar (alta i edició). La resta s'ignoren (o 422 amb strictInput).
     *
     * @param list<string> $fields
     */
    public function writeFields(array $fields): static
    {
        $this->writeFields = array_values($fields);

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function addFields(array $fields): static
    {
        $this->addFields = array_values($fields);

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function editFields(array $fields): static
    {
        $this->editFields = array_values($fields);

        return $this;
    }

    /**
     * Amaga camps de tot arreu (sortida, filtres, cerca, ordenació i entrada).
     * Té prioritat sobre les altres llistes.
     *
     * @param list<string> $fields
     */
    public function hideFields(array $fields): static
    {
        $this->hidden = array_values(array_unique([...$this->hidden, ...$fields]));

        return $this;
    }

    /**
     * Camps que es poden enviar en l'alta però no es poden canviar després.
     *
     * @param list<string> $fields
     */
    public function readonlyFields(array $fields): static
    {
        $this->readonly = array_values(array_unique([...$this->readonly, ...$fields]));

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function requiredFields(array $fields): static
    {
        $this->required = array_values(array_unique([...$this->required, ...$fields]));

        return $this;
    }

    public function setDefault(string $field, mixed $value): static
    {
        $this->defaults[$field] = $value;

        return $this;
    }

    /**
     * Regles de validació de CodeIgniter. {id} és l'id real en editar.
     *
     * @param array<string, list<string>|string>|string $field
     * @param array<string, string>                     $messages
     */
    public function setRules(array|string $field, array|string|null $rules = null, array $messages = []): static
    {
        $list = is_array($field) ? $field : [$field => $rules ?? ''];

        foreach ($list as $name => $value) {
            $this->rules[(string) $name] = is_array($value) ? array_values($value) : explode('|', (string) $value);

            if ($messages !== []) {
                $this->ruleMessages[(string) $name] = $messages;
            }
        }

        return $this;
    }

    /**
     * Força el tipus d'un camp: int, float, decimal, bool, date, datetime, time, json,
     * text, email, url, enum/select ('options' => [valor => etiqueta]), multiselect
     * ('options', 'storage' => json|csv), password, upload/image ('path', 'url', 'allowed',
     * 'maxSize', 'subdir'), virtual.
     *
     * @param array<string, mixed> $options
     */
    public function fieldType(string $field, string $type, array $options = []): static
    {
        $this->types[$field] = ['type' => $type, 'options' => $options];

        return $this;
    }

    /**
     * Valor de sortida d'un camp (també camps virtuals): fn (mixed $value, array $row): mixed
     */
    public function callbackField(string $field, Closure $callback): static
    {
        $this->fieldCallbacks[$field] = $callback;

        return $this;
    }

    /**
     * Transforma cada element de sortida: fn (array $item, array $row): array
     */
    public function transform(Closure $callback): static
    {
        $this->transformer = $callback;

        return $this;
    }

    public function softDeletes(bool $enabled = true, string $field = 'deleted_at'): static
    {
        $this->softDeletesSet = $enabled;
        $this->deletedField   = $field;

        return $this;
    }

    // ------------------------------------------------------------------
    // Relacions i traduccions
    // ------------------------------------------------------------------

    /**
     * Relació 1-N. Surt com la clau forana i, amb ?include=nom, com un objecte amb les columnes.
     *
     * @param list<string>|string  $columns Columnes de la taula relacionada que s'inclouen
     * @param array<string, mixed> $options 'as' (nom per a ?include, per defecte el camp sense _id),
     *                                      'pk' ('id'), 'where' (opcions vàlides)
     */
    public function setRelation(string $field, string $table, array|string $columns, array $options = []): static
    {
        $columns = is_array($columns) ? array_values($columns) : array_map('trim', explode(',', $columns));
        $pk      = (string) ($options['pk'] ?? 'id');

        $this->relations[$field] = [
            'table'   => $table,
            'columns' => array_values(array_unique([$pk, ...$columns])),
            'pk'      => $pk,
            'as'      => (string) ($options['as'] ?? (preg_replace('/_id$/', '', $field) ?: $field)),
            'where'   => (array) ($options['where'] ?? []),
        ];

        return $this;
    }

    /**
     * Relació N-N amb una taula intermèdia. Surt com una llista d'id i, amb ?include=nom,
     * com una llista d'objectes. S'escriu enviant la llista d'id.
     *
     * @param list<string>|string  $columns
     * @param array<string, mixed> $options 'pk' ('id'), 'where'
     */
    public function setRelationNN(string $name, string $junction, string $otherTable, string $thisKey, string $otherKey, array|string $columns, array $options = []): static
    {
        $columns = is_array($columns) ? array_values($columns) : array_map('trim', explode(',', $columns));
        $pk      = (string) ($options['pk'] ?? 'id');

        $this->relationsNN[$name] = [
            'junction' => $junction,
            'table'    => $otherTable,
            'thisKey'  => $thisKey,
            'otherKey' => $otherKey,
            'columns'  => array_values(array_unique([$pk, ...$columns])),
            'pk'       => $pk,
            'where'    => (array) ($options['where'] ?? []),
        ];

        return $this;
    }

    /**
     * Camps traduïts en una taula filla, una fila per idioma.
     * Surten en l'idioma demanat (?lang=ca o Accept-Language) o tots amb ?lang=*.
     *
     * @param list<string>                       $fields
     * @param array<string, string>|list<string> $locales
     * @param array<string, mixed>               $options 'required' (idiomes obligatoris; per defecte el primer)
     */
    public function setTranslations(string $table, string $foreignKey, string $localeField, array $fields, array $locales, array $options = []): static
    {
        $codes = [];

        foreach ($locales as $code => $label) {
            $codes[] = is_int($code) ? (string) $label : (string) $code;
        }

        if ($codes === [] || $fields === []) {
            throw new RuntimeException(lang('Kpapy.exceptions.translationsEmpty'));
        }

        $this->translations = [
            'table'    => $table,
            'fk'       => $foreignKey,
            'locale'   => $localeField,
            'fields'   => array_values($fields),
            'locales'  => $codes,
            'required' => array_values(array_intersect($codes, (array) ($options['required'] ?? [$codes[0]]))),
        ];

        return $this;
    }

    // ------------------------------------------------------------------
    // Llistat
    // ------------------------------------------------------------------

    /**
     * Camps que es poden filtrar (?filter[camp]=valor). Per defecte: relacions, enum/select,
     * booleans i multiselect.
     *
     * @param list<string> $fields
     */
    public function filters(array $fields): static
    {
        $this->filterFields = array_values($fields);

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function searchFields(array $fields): static
    {
        $this->searchFields = array_values($fields);

        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function unsortable(array $fields): static
    {
        $this->unsortable = array_values($fields);

        return $this;
    }

    public function defaultOrder(string $field, string $direction = 'asc'): static
    {
        $this->defaultOrder = [$field, strtolower($direction) === 'desc' ? 'desc' : 'asc'];

        return $this;
    }

    public function setPerPage(int $perPage): static
    {
        $this->perPage = max(1, $perPage);

        return $this;
    }

    /**
     * Paginació per cursor (?cursor=...) en lloc de per pàgines, per a taules grans i
     * desplaçament infinit. El camp ha de ser ordenable i, juntament amb la clau, únic.
     */
    public function cursorPagination(string $field, string $direction = 'desc'): static
    {
        $this->cursor = [$field, strtolower($direction) === 'asc' ? 'asc' : 'desc'];

        return $this;
    }

    /**
     * No compta el total de registres (taules molt grans): meta.total i links.last no surten.
     */
    public function withoutTotal(): static
    {
        $this->withTotal = false;

        return $this;
    }

    // ------------------------------------------------------------------
    // Estructura
    // ------------------------------------------------------------------

    protected function boot(): void
    {
        if ($this->booted) {
            return;
        }

        if ($this->model === null && $this->table === null) {
            throw new RuntimeException(lang('Kpapy.exceptions.missingSource'));
        }

        if ($this->model !== null) {
            $model = $this->model;

            $this->table ??= $model->table;
            $this->primaryKey ??= $model->primaryKey;
            $this->db ??= $model->db;
            $this->softDeletes  = (bool) $model->useSoftDeletes;
            $this->deletedField = $model->deletedField ?: 'deleted_at';

            if ($model->useTimestamps) {
                $this->createdField = $model->createdField ?: null;
                $this->updatedField = $model->updatedField ?: null;
            }

            $this->writeFields ??= $model->allowedFields !== [] ? array_values($model->allowedFields) : null;

            foreach ($model->getValidationRules() as $field => $rules) {
                if (! isset($this->rules[$field])) {
                    $rules = is_array($rules) && isset($rules['rules']) ? $rules['rules'] : $rules;
                    $this->rules[$field] = is_array($rules) ? array_values($rules) : explode('|', (string) $rules);
                }
            }

            foreach ($model->getValidationMessages() as $field => $messages) {
                $this->ruleMessages[$field] ??= (array) $messages;
            }
        }

        $this->db ??= db_connect();

        if (! $this->db->tableExists($this->table)) {
            throw new RuntimeException(lang('Kpapy.exceptions.tableNotFound', [$this->table]));
        }

        foreach ($this->db->getFieldData($this->table) as $field) {
            $this->meta[$field->name] = [
                'type'       => strtolower((string) $field->type),
                'max_length' => isset($field->max_length) ? (int) $field->max_length : null,
                'nullable'   => (bool) ($field->nullable ?? true),
                'default'    => $field->default ?? null,
                'primary'    => (bool) ($field->primary_key ?? false),
            ];
        }

        if ($this->primaryKey === null) {
            foreach ($this->meta as $name => $info) {
                if ($info['primary']) {
                    $this->primaryKey = $name;
                    break;
                }
            }

            $this->primaryKey ??= isset($this->meta['id']) ? 'id' : array_key_first($this->meta);
        }

        if ($this->model === null) {
            $this->softDeletes  = isset($this->meta[$this->deletedField]);
            $this->createdField = isset($this->meta['created_at']) ? 'created_at' : null;
            $this->updatedField = isset($this->meta['updated_at']) ? 'updated_at' : null;
        }

        if ($this->softDeletesSet !== null) {
            $this->softDeletes = $this->softDeletesSet && isset($this->meta[$this->deletedField]);
        }

        $this->booted = true;
    }

    /**
     * Tipus d'un camp: el forçat amb fieldType() o el que es dedueix de la base de dades.
     */
    protected function typeOf(string $name): string
    {
        if (isset($this->types[$name])) {
            return $this->types[$name]['type'] === 'select' ? 'enum' : $this->types[$name]['type'];
        }

        if (isset($this->relationsNN[$name])) {
            return 'relation_nn';
        }

        if ($this->isTranslated($name)) {
            return 'translation';
        }

        if (! isset($this->meta[$name])) {
            return 'virtual';
        }

        $type   = $this->meta[$name]['type'];
        $length = $this->meta[$name]['max_length'];

        return match (true) {
            in_array($type, ['tinyint', 'bool', 'boolean'], true) && $length === 1 => 'bool',
            in_array($type, ['int', 'integer', 'smallint', 'mediumint', 'bigint', 'tinyint', 'serial'], true) => 'int',
            in_array($type, ['decimal', 'numeric'], true) => 'decimal',
            in_array($type, ['float', 'double', 'real', 'double precision'], true) => 'float',
            $type === 'date'                                                  => 'date',
            in_array($type, ['datetime', 'timestamp', 'timestamptz'], true)    => 'datetime',
            $type === 'time'                                                  => 'time',
            in_array($type, ['json', 'jsonb'], true)                          => 'json',
            default => $this->nameType($name),
        };
    }

    protected function nameType(string $name): string
    {
        return match (true) {
            str_contains($name, 'email') => 'email',
            $name === 'url' || str_ends_with($name, '_url') || $name === 'website' => 'url',
            default => 'text',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function typeOptions(string $name): array
    {
        return $this->types[$name]['options'] ?? [];
    }

    protected function isTranslated(string $name): bool
    {
        return $this->translations !== null && in_array($name, $this->translations['fields'], true);
    }

    protected function isSensitive(string $name): bool
    {
        if (($this->types[$name]['type'] ?? null) === 'password') {
            return true;
        }

        $lower = strtolower($name);

        if (in_array(str_replace('_', '', $lower), $this->config->sensitiveFields, true)) {
            return true;
        }

        return array_intersect(explode('_', $lower), $this->config->sensitiveFields) !== [];
    }

    /**
     * @param list<string> $fields
     *
     * @return list<string>
     */
    protected function withoutHidden(array $fields): array
    {
        return $this->hidden === [] ? array_values($fields) : array_values(array_diff($fields, $this->hidden));
    }

    /**
     * Camps de sortida del detall.
     *
     * @return list<string>
     */
    protected function outputFields(): array
    {
        $fields = $this->fields ?? array_values(array_merge(
            array_filter(array_keys($this->meta), fn (string $name): bool => $name !== $this->deletedField && ! $this->isSensitive($name)),
            $this->translations['fields'] ?? [],
            array_keys($this->relationsNN),
        ));

        // La clau primària sempre hi és: és l'id del recurs
        if (! in_array($this->primaryKey, $fields, true)) {
            array_unshift($fields, (string) $this->primaryKey);
        }

        return $this->withoutHidden($fields);
    }

    /**
     * @return list<string>
     */
    protected function listOutputFields(): array
    {
        if ($this->listFields === null) {
            return $this->outputFields();
        }

        $fields = $this->listFields;

        if (! in_array($this->primaryKey, $fields, true)) {
            array_unshift($fields, (string) $this->primaryKey);
        }

        return $this->withoutHidden($fields);
    }

    /**
     * Camps que el client pot enviar en una alta ('add') o una edició ('edit').
     *
     * @return list<string>
     */
    protected function inputFields(string $mode): array
    {
        $fields = ($mode === 'add' ? $this->addFields : $this->editFields) ?? $this->writeFields;

        if ($fields === null) {
            $exclude = [$this->primaryKey, $this->createdField, $this->updatedField, $this->deletedField];
            $fields  = array_values(array_merge(
                array_filter(array_keys($this->meta), fn (string $name): bool => ! in_array($name, $exclude, true)
                    && (! $this->isSensitive($name) || ($this->types[$name]['type'] ?? null) === 'password')),
                $this->translations['fields'] ?? [],
                array_keys($this->relationsNN),
            ));
        } else {
            // Les relacions N-N i els camps traduïts declarats també es poden escriure
            $fields = array_values(array_unique([...$fields, ...array_intersect($this->fields ?? [], [...array_keys($this->relationsNN), ...($this->translations['fields'] ?? [])])]));
        }

        $fields = array_filter($fields, fn (string $name): bool => ! in_array($this->typeOf($name), ['virtual'], true) || isset($this->types[$name]));

        if ($mode === 'edit') {
            $fields = array_diff($fields, $this->readonly);
        }

        return $this->withoutHidden(array_values($fields));
    }

    /**
     * @return list<string>
     */
    protected function filterFieldList(): array
    {
        $fields = $this->filterFields ?? array_values(array_filter(
            $this->outputFields(),
            fn (string $name): bool => isset($this->relations[$name]) || in_array($this->typeOf($name), ['enum', 'bool', 'multiselect'], true),
        ));

        return $this->withoutHidden($fields);
    }

    /**
     * @return list<string>
     */
    protected function searchFieldList(): array
    {
        $fields = $this->searchFields ?? array_values(array_filter(
            $this->outputFields(),
            fn (string $name): bool => (isset($this->meta[$name]) || $this->isTranslated($name))
                && ! isset($this->fieldCallbacks[$name]) && ! $this->isSensitive($name)
                && in_array($this->typeOf($name), ['text', 'email', 'url', 'translation'], true)
                && ! isset($this->relations[$name]),
        ));

        return $this->withoutHidden($fields);
    }

    /**
     * @return list<string>
     */
    protected function sortableFields(): array
    {
        return array_values(array_filter(
            $this->outputFields(),
            fn (string $name): bool => (isset($this->meta[$name]) || $this->isTranslated($name)) && ! in_array($name, $this->unsortable, true),
        ));
    }

    /**
     * Regles de validació finals d'un camp.
     *
     * @return list<string>
     */
    protected function fieldRules(string $name, string $mode, ?string $id): array
    {
        $type  = $this->typeOf($name);
        $rules = $this->rules[$name] ?? [];
        $meta  = $this->meta[$name] ?? null;

        if (in_array($name, $this->required, true)) {
            $rules[] = 'required';
        }

        if ($this->config->inferRules && $meta !== null && ! isset($this->rules[$name])) {
            if (! $meta['nullable'] && $meta['default'] === null && ! $meta['primary']
                && ! in_array($type, ['bool', 'upload', 'image'], true) && ! array_key_exists($name, $this->defaults)) {
                $rules[] = 'required';
            }

            if ($meta['max_length'] && in_array($type, ['text', 'email', 'url'], true)) {
                $rules[] = "max_length[{$meta['max_length']}]";
            }

            $typeRule = match ($type) {
                'email'   => 'valid_email',
                'url'     => 'valid_url_strict[http,https]',
                'int'     => 'integer',
                'decimal' => 'decimal',
                'float'   => 'numeric',
                default   => null,
            };

            if ($typeRule !== null) {
                $rules[] = $typeRule;
            }
        }

        if ($type === 'password' && $mode === 'edit') {
            $rules = array_filter($rules, static fn (string $rule): bool => $rule !== 'required');
        }

        $rules = array_values(array_unique(array_map(
            static fn (string $rule): string => str_replace('{id}', (string) $id, trim($rule)),
            array_filter($rules, static fn (string $rule): bool => trim($rule) !== ''),
        )));

        if ($rules !== [] && ! in_array('required', $rules, true) && ! in_array('permit_empty', $rules, true)) {
            array_unshift($rules, 'permit_empty');
        }

        return $rules;
    }

    protected function isValidId(string $id): bool
    {
        $type = $this->meta[$this->primaryKey]['type'] ?? 'int';

        if (in_array($type, ['int', 'integer', 'smallint', 'mediumint', 'bigint', 'tinyint', 'serial'], true)) {
            return ctype_digit($id) && strlen($id) <= 20;
        }

        return $id !== '' && strlen($id) <= 255;
    }
}
