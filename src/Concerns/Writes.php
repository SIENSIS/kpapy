<?php

declare(strict_types=1);

namespace Kpapy\Concerns;

use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Kpapy\Http\ApiException;
use Kpapy\Secret;
use Throwable;

/**
 * Escriptura: alta, edició, esborrat, restauració, operacions pròpies, accions massives i fitxers.
 *
 * @mixin \Kpapy\Resource
 */
trait Writes
{
    // ------------------------------------------------------------------
    // Operacions
    // ------------------------------------------------------------------

    protected function createOperation(): ResponseInterface
    {
        if (! $this->can('add')) {
            throw ApiException::forbidden();
        }

        $input = $this->readInput('add');
        [$data, $nn, $tr, $files] = $this->prepareData($input, 'add', null, null);

        $id  = $this->save('add', null, null, $data, $nn, $tr, $files);
        $row = $this->findRecord($id, 'with') ?? throw ApiException::notFound();

        return $this->itemResponse($row, 201, ['Location' => $this->url($id)]);
    }

    protected function updateOperation(string $id, bool $full): ResponseInterface
    {
        $old = $this->findRecord($id);

        if ($old === null) {
            throw ApiException::notFound();
        }

        if (! $this->can('edit', $old)) {
            throw ApiException::forbidden();
        }

        $this->checkIfMatch($old);

        $input = $this->readInput('edit');
        [$data, $nn, $tr, $files] = $this->prepareData($input, 'edit', $id, $old, $full);

        $this->save('edit', $id, $old, $data, $nn, $tr, $files);

        return $this->itemResponse($this->findRecord($id, 'with') ?? throw ApiException::notFound(), 200);
    }

    protected function deleteOperation(string $id): ResponseInterface
    {
        $purge = in_array($this->request->getGet('purge'), ['1', 'true'], true);
        $row   = $this->findRecord($id, $purge ? 'with' : 'none');

        if ($row === null) {
            throw ApiException::notFound();
        }

        $permission = $purge && $this->softDeletes ? 'purge' : 'delete';

        if (! $this->can($permission, $row)) {
            throw ApiException::forbidden();
        }

        $this->checkIfMatch($row);
        $this->deleteRecord($id, $row, $purge || ! $this->softDeletes);

        return $this->responder->noContent();
    }

    protected function restoreOperation(string $id): ResponseInterface
    {
        $row = $this->findRecord($id, 'only');

        if ($row === null) {
            throw ApiException::notFound();
        }

        if (! $this->can('restore', $row)) {
            throw ApiException::forbidden();
        }

        $this->restoreRecord($id, $row);

        return $this->itemResponse($this->findRecord($id) ?? throw ApiException::notFound(), 200);
    }

    protected function customOperation(string $key, ?string $id, string $method): ResponseInterface
    {
        $operation = $this->operations[$key];

        if ($method !== $operation['method']) {
            throw ApiException::methodNotAllowed([$operation['method']]);
        }

        $row = null;

        if ($operation['row']) {
            $row = $this->findRecord((string) $id, $operation['trash'] ? 'only' : 'none') ?? throw ApiException::notFound();
        }

        if (($operation['trash'] && ! $this->can('trash', $row))
            || ! $this->can($operation['permission'], $row)
            || ($operation['visible'] instanceof Closure && ! ($operation['visible'])($row))) {
            throw ApiException::forbidden();
        }

        $result = ($operation['callback'])($id, $row, $this);

        return $this->operationResponse($result, $operation['status']);
    }

    protected function operationResponse(mixed $result, int $status = 200): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            $result === null, $result === true   => $this->responder->noContent(),
            $result === false                    => throw new ApiException(409, 'operation-failed'),
            is_string($result)                   => $this->responder->item(['message' => $result], [], [], $status),
            default                              => $this->responder->item((array) $result, [], [], $status),
        };
    }

    protected function bulkOperation(): ResponseInterface
    {
        if (! $this->can('bulk')) {
            throw ApiException::forbidden();
        }

        $body   = $this->jsonBody();
        $action = $body['action'] ?? null;
        $codes  = $body['ids'] ?? null;

        if (! is_string($action) || ! is_array($codes) || $codes === [] || count($codes) > 1000) {
            throw ApiException::validation(['ids' => [lang('Kpapy.bulkInvalid')]]);
        }

        $builtin    = ['delete' => 'delete', 'restore' => 'restore', 'purge' => 'purge'];
        $custom     = $this->bulkActions[$action] ?? null;
        $permission = $custom !== null ? ($custom['permission'] ?? null) : ($builtin[$action] ?? throw ApiException::validation(['action' => [lang('Kpapy.bulkUnknown', [$action])]]));
        $trash      = in_array($action, ['restore', 'purge'], true) ? 'with' : 'none';

        $processed = [];
        $skipped   = [];
        $rows      = [];

        foreach ($codes as $code) {
            $code = is_scalar($code) ? (string) $code : '';
            $id   = $this->decodeId($code);
            $row  = $id === null ? null : $this->findRecord($id, $trash);

            if ($row === null) {
                $skipped[] = ['id' => $code, 'reason' => 'not-found'];

                continue;
            }

            if ($permission !== null && ! $this->can($permission, $row)) {
                $skipped[] = ['id' => $code, 'reason' => 'forbidden'];

                continue;
            }

            $rows[$id] = $row;
        }

        $message = null;

        if ($rows !== []) {
            if ($custom !== null) {
                $message = ($custom['callback'])(array_map('strval', array_keys($rows)), $this);
            } else {
                foreach ($rows as $id => $row) {
                    $id = (string) $id;

                    match ($action) {
                        'delete'  => $this->deleteRecord($id, $row, ! $this->softDeletes),
                        'purge'   => $this->deleteRecord($id, $row, true),
                        'restore' => $this->restoreRecord($id, $row),
                    };
                }
            }

            $processed = array_map(fn ($id): string => $this->encodeId((string) $id), array_keys($rows));
        }

        return $this->responder->item(array_filter([
            'processed' => $processed,
            'skipped'   => $skipped,
            'message'   => is_string($message) ? $message : null,
        ], static fn ($v): bool => $v !== null));
    }

    // ------------------------------------------------------------------
    // Entrada
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(): array
    {
        $raw = (string) $this->request->getBody();

        if (strlen($raw) > $this->config->maxBodySize * 1024) {
            throw new ApiException(413, 'payload-too-large');
        }

        if (trim($raw) === '') {
            return [];
        }

        $type = strtolower($this->request->getHeaderLine('Content-Type'));

        if (! str_contains($type, 'json')) {
            throw ApiException::unsupportedMediaType();
        }

        try {
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ApiException::badRequest(lang('Kpapy.invalidJson'));
        }

        if (! is_array($body) || array_is_list($body) && $body !== []) {
            throw ApiException::badRequest(lang('Kpapy.invalidJson'));
        }

        return $body;
    }

    /**
     * Cos de la petició (JSON o multipart) filtrat als camps que es poden escriure.
     *
     * @return array<string, mixed>
     */
    protected function readInput(string $mode): array
    {
        $type = strtolower($this->request->getHeaderLine('Content-Type'));
        $body = str_contains($type, 'multipart/form-data') || str_contains($type, 'application/x-www-form-urlencoded')
            ? (array) $this->request->getPost()
            : $this->jsonBody();

        $allowed = $this->inputFields($mode);
        $input   = [];
        $extra   = [];

        foreach ($body as $key => $value) {
            $key = (string) $key;

            if (in_array($key, $allowed, true)) {
                $input[$key] = $value;
            } elseif ($key !== $this->primaryKey) {
                $extra[] = $key;
            }
        }

        if ($extra !== [] && $this->config->strictInput) {
            throw ApiException::validation(array_fill_keys($extra, [lang('Kpapy.fieldNotWritable')]));
        }

        return $input;
    }

    /**
     * Converteix i valida l'entrada.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $old
     *
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>, 2: array<string, array<string, mixed>>, 3: array<string, UploadedFile>}
     */
    protected function prepareData(array $input, string $mode, ?string $id, ?array $old, bool $full = false): array
    {
        $data   = [];
        $nn     = [];
        $tr     = [];
        $files  = [];
        $errors = [];
        $scope  = $this->scopeValues();
        $fields = $this->inputFields($mode);

        // Alta (i PUT): els valors per defecte dels camps que no s'envien
        if ($mode === 'add') {
            foreach ($this->defaults as $field => $value) {
                if (! array_key_exists($field, $input) && in_array($field, $fields, true)) {
                    $input[$field] = $value instanceof Closure ? $value() : $value;
                }
            }
        }

        foreach ($fields as $field) {
            if (array_key_exists($field, $scope)) {
                continue;
            }

            $type = $this->typeOf($field);

            if (in_array($type, ['upload', 'image'], true)) {
                $file = $this->request->getFile($field);

                if ($file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                    $files[$field] = $file;
                }

                continue;
            }

            if (! array_key_exists($field, $input)) {
                continue;
            }

            try {
                $value = $input[$field];

                if ($type === 'relation_nn') {
                    $nn[$field] = $this->convertNN($field, $value);
                } elseif ($type === 'translation') {
                    $tr[$field] = $this->convertTranslation($field, $value);
                } else {
                    $converted = $this->convertInput($field, $value, $mode);

                    if ($converted !== ['skip']) {
                        $data[$field] = $converted[0];
                    }
                }
            } catch (ApiException $e) {
                $errors[$field] = [$e->getMessage()];
            }
        }

        foreach ($scope as $field => $value) {
            $data[$field] = $value;
        }

        // Validació: tots els camps a l'alta i amb PUT; només els enviats amb PATCH
        $validate = $mode === 'add' || $full ? $fields : array_keys([...$data, ...$nn]);
        $rules    = [];

        foreach ($validate as $field) {
            if ($this->isTranslated($field) || in_array($this->typeOf($field), ['upload', 'image'], true) || array_key_exists($field, $scope)) {
                continue;
            }

            $fieldRules = $this->fieldRules($field, $mode, $id);

            if ($fieldRules !== []) {
                $rules[$field] = ['label' => $field, 'rules' => $fieldRules, 'errors' => $this->ruleMessages[$field] ?? []];
            }
        }

        if ($rules !== []) {
            $validation = service('validation', null, false);
            $values     = $data;

            foreach ($nn as $field => $ids) {
                $values[$field] = $ids === [] ? '' : $ids;
            }

            if ($id !== null) {
                $values[$this->primaryKey] = $id;
            }

            if (! $validation->setRules($rules)->run($values)) {
                foreach ($validation->getErrors() as $field => $message) {
                    $errors[$field] ??= [$message];
                }
            }
        }

        $errors += $this->validateTranslations($tr, $mode, $id);

        foreach ($files as $field => $file) {
            if (($message = $this->checkUpload($field, $file)) !== null) {
                $errors[$field] = [$message];
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return [$data, $nn, $tr, $files];
    }

    /**
     * Valor que es desa d'un camp. ['skip'] = no es desa (p. ex. contrasenya buida en editar).
     *
     * @return array{0: mixed}|array{0: 'skip'}
     */
    protected function convertInput(string $field, mixed $value, string $mode): array
    {
        $type    = $this->typeOf($field);
        $options = $this->typeOptions($field);

        if (isset($this->relations[$field])) {
            if ($value === null || $value === '') {
                return [null];
            }

            $relation = $this->relations[$field];
            $decoded  = is_scalar($value) ? $this->decodeId((string) $value, $relation['table']) : null;

            if ($decoded === null || ! $this->relatedExists($relation, [$decoded])) {
                throw new ApiException(422, 'validation', lang('Kpapy.invalidRelation'));
            }

            return [$decoded];
        }

        if ($value === null) {
            return [null];
        }

        switch ($type) {
            case 'bool':
                if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidBool'));
                }

                return [in_array($value, [true, 1, '1', 'true'], true) ? 1 : 0];

            case 'int':
                if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value))) {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidNumber'));
                }

                return [(int) $value];

            case 'float':
            case 'decimal':
                if (! is_numeric($value)) {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidNumber'));
                }

                return [(string) $value];

            case 'date':
            case 'datetime':
                $date = is_string($value) ? $this->parseDate($value, $type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s') : null;

                if ($date === null && $value !== '') {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidDate'));
                }

                return [$date];

            case 'json':
                return [json_encode($value, JSON_UNESCAPED_UNICODE)];

            case 'enum':
                if (! is_scalar($value) || ! array_key_exists((string) $value, (array) ($options['options'] ?? []))) {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidOption'));
                }

                return [(string) $value];

            case 'multiselect':
                $values  = is_array($value) ? array_values(array_map('strval', $value)) : $this->decodeMulti($value);
                $invalid = array_diff($values, array_map('strval', array_keys((array) ($options['options'] ?? []))));

                if ($invalid !== []) {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidOption'));
                }

                $values = array_values(array_unique($values));

                if ($values === []) {
                    return [($this->meta[$field]['nullable'] ?? true) ? null : ''];
                }

                return [($options['storage'] ?? 'json') === 'csv' ? implode(',', $values) : json_encode($values, JSON_UNESCAPED_UNICODE)];

            case 'password':
                if (! is_string($value)) {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidText'));
                }

                if ($value === '' && $mode === 'edit') {
                    return ['skip'];
                }

                return [($options['hash'] ?? true) ? password_hash($value, PASSWORD_DEFAULT) : $value];

            default:
                if (! is_scalar($value)) {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidText'));
                }

                return [is_bool($value) ? (int) $value : (string) $value];
        }
    }

    /**
     * @return list<string>
     */
    protected function convertNN(string $field, mixed $value): array
    {
        $nn = $this->relationsNN[$field];

        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            throw new ApiException(422, 'validation', lang('Kpapy.invalidRelation'));
        }

        $ids = [];

        foreach ($value as $code) {
            $decoded = is_scalar($code) ? $this->decodeId((string) $code, $nn['table']) : null;

            if ($decoded === null) {
                throw new ApiException(422, 'validation', lang('Kpapy.invalidRelation'));
            }

            $ids[] = $decoded;
        }

        $ids = array_values(array_unique($ids));

        if ($ids !== [] && ! $this->relatedExists($nn, $ids)) {
            throw new ApiException(422, 'validation', lang('Kpapy.invalidRelation'));
        }

        return $ids;
    }

    /**
     * Valors d'un camp traduït: un text (idioma de la petició) o un objecte {idioma: text}.
     *
     * @return array<string, mixed>
     */
    protected function convertTranslation(string $field, mixed $value): array
    {
        $locales = $this->translations['locales'];

        if (is_array($value)) {
            $result = [];

            foreach ($value as $locale => $text) {
                if (! in_array((string) $locale, $locales, true) || ($text !== null && ! is_scalar($text))) {
                    throw new ApiException(422, 'validation', lang('Kpapy.invalidTranslation'));
                }

                $result[(string) $locale] = $text === null ? null : (string) $text;
            }

            return $result;
        }

        if ($value !== null && ! is_scalar($value)) {
            throw new ApiException(422, 'validation', lang('Kpapy.invalidTranslation'));
        }

        return [$this->locale() ?? $this->localeChain()[0] => $value === null ? null : (string) $value];
    }

    /**
     * Els idiomes obligatoris han de tenir tots els camps (a l'alta) i no es poden buidar.
     *
     * @param array<string, array<string, mixed>> $tr
     *
     * @return array<string, list<string>>
     */
    protected function validateTranslations(array $tr, string $mode, ?string $id): array
    {
        if ($this->translations === null) {
            return [];
        }

        $errors   = [];
        $existing = $id !== null ? ($this->loadTranslations([$id])[$id] ?? []) : [];

        foreach ($this->inputFields($mode) as $field) {
            if (! $this->isTranslated($field)) {
                continue;
            }

            if (! $this->translationRequired($field)) {
                continue;
            }

            // Als idiomes obligatoris, el camp ha de tenir valor a l'alta i no es pot buidar en editar
            foreach ($this->translations['required'] as $locale) {
                $sent  = array_key_exists($locale, $tr[$field] ?? []);
                $value = $sent ? $tr[$field][$locale] : ($existing[$locale][$field] ?? null);

                if (($mode === 'add' || $sent) && ($value === null || trim((string) $value) === '')) {
                    $errors[$field][] = lang('Kpapy.translationRequired', [$locale]);
                }
            }
        }

        return $errors;
    }

    /**
     * Un camp traduït és obligatori si la columna de la taula de traduccions no admet NULL.
     */
    protected function translationRequired(string $field): bool
    {
        static $cache = [];

        $tr  = $this->translations;
        $key = $tr['table'] . '.' . $field;

        if (! array_key_exists($key, $cache)) {
            $cache[$key] = false;

            foreach ($this->db->getFieldData($tr['table']) as $column) {
                if ($column->name === $field) {
                    $cache[$key] = ! ($column->nullable ?? true) && ($column->default ?? null) === null;
                }
            }
        }

        return $cache[$key] || in_array($field, $this->required, true) || in_array('required', $this->rules[$field] ?? [], true);
    }

    /**
     * @param array<string, mixed> $relation
     * @param list<string>         $ids
     */
    protected function relatedExists(array $relation, array $ids): bool
    {
        $builder = $this->db->table($relation['table'])->whereIn($relation['pk'], $ids);

        foreach ($relation['where'] as $field => $value) {
            $builder->where($field, $value);
        }

        return $builder->countAllResults() === count(array_unique($ids));
    }

    /**
     * Valors d'igualtat de where(): s'imposen en escriure.
     *
     * @return array<string, mixed>
     */
    protected function scopeValues(): array
    {
        $values = [];

        foreach ($this->wheres as [$field, $value]) {
            if (! str_contains($field, ' ') && ! str_contains($field, '.') && (is_scalar($value) || $value === null)) {
                $values[$field] = $value;
            }
        }

        return $values;
    }

    /**
     * Concurrència optimista: If-Match ha de coincidir amb l'ETag actual.
     *
     * @param array<string, mixed> $row
     */
    protected function checkIfMatch(array $row): void
    {
        $header = $this->request->getHeaderLine('If-Match');

        if ($header === '') {
            if ($this->config->requireIfMatch) {
                throw ApiException::preconditionRequired();
            }

            return;
        }

        $tags = array_map('trim', explode(',', $header));

        if (! in_array('*', $tags, true) && ! in_array($this->etagFor($row), $tags, true)) {
            throw ApiException::preconditionFailed();
        }
    }

    // ------------------------------------------------------------------
    // Desar i esborrar
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>                $data
     * @param array<string, list<string>>         $nn
     * @param array<string, array<string, mixed>> $tr
     * @param array<string, UploadedFile>         $files
     * @param array<string, mixed>|null           $old
     */
    protected function save(string $mode, ?string $id, ?array $old, array $data, array $nn, array $tr, array $files): string
    {
        $hook   = $mode === 'add' ? 'beforeInsert' : 'beforeUpdate';
        $result = $this->callHook($hook, $data, $id, $old);

        if ($result === false) {
            throw new ApiException(409, 'operation-failed');
        }

        if (is_string($result)) {
            throw new ApiException(422, 'validation', $result);
        }

        if (is_array($result)) {
            $data = $result;
        }

        // Un registre editat ha de continuar sent permès (p. ex. no es pot canviar el propietari)
        if ($mode === 'edit' && ! $this->can('edit', [...(array) $old, ...$data])) {
            throw ApiException::forbidden();
        }

        $stored = [];

        foreach ($files as $field => $file) {
            $stored[$field] = $this->storeUpload($field, $file, [...(array) $old, ...$data]);
            $data[$field]   = $stored[$field];
        }

        $this->db->transException(true)->transStart();

        try {
            $now = date('Y-m-d H:i:s');

            if ($mode === 'add') {
                if ($this->createdField !== null && ! array_key_exists($this->createdField, $data)) {
                    $data[$this->createdField] = $now;
                }

                if ($this->updatedField !== null) {
                    $data[$this->updatedField] = $now;
                }

                if ($this->model !== null) {
                    $id = (string) $this->model->skipValidation(true)->insert($data, true);
                } else {
                    $this->db->table($this->table)->insert($data);
                    $id = (string) ($data[$this->primaryKey] ?? $this->db->insertID());
                }
            } else {
                if ($this->updatedField !== null && ($data !== [] || $nn !== [] || $tr !== [])) {
                    $data[$this->updatedField] = $now;
                }

                if ($data !== []) {
                    $this->model !== null
                        ? $this->model->skipValidation(true)->update($id, $data)
                        : $this->db->table($this->table)->where($this->primaryKey, $id)->update($data);
                }
            }

            foreach ($nn as $field => $ids) {
                $this->syncNN($field, (string) $id, $ids);
            }

            if ($tr !== []) {
                $this->syncTranslations((string) $id, $tr);
            }

            $this->db->transComplete();
        } catch (Throwable $e) {
            $this->db->transRollback();

            foreach ($stored as $path) {
                @unlink($this->uploadDir(array_search($path, $stored, true)) . '/' . $path);
            }

            throw $e;
        } finally {
            $this->db->transException(false);
        }

        // Fitxers substituïts
        foreach ($stored as $field => $path) {
            if ($old !== null && ! empty($old[$field]) && $old[$field] !== $path) {
                $this->removeFile($field, (string) $old[$field]);
            }
        }

        $this->callHook($mode === 'add' ? 'afterInsert' : 'afterUpdate', (string) $id, $data, $old);
        $this->callHook('afterWrite', $mode === 'add' ? 'insert' : 'update', (string) $id, $data, $old);

        return (string) $id;
    }

    /**
     * @param list<string> $ids
     */
    protected function syncNN(string $field, string $id, array $ids): void
    {
        $nn      = $this->relationsNN[$field];
        $builder = $this->db->table($nn['junction']);

        $builder->where($nn['thisKey'], $id)->delete();

        if ($ids !== []) {
            $this->db->table($nn['junction'])->insertBatch(array_map(static fn (string $other): array => [$nn['thisKey'] => $id, $nn['otherKey'] => $other], $ids));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $tr camp => [idioma => valor]
     */
    protected function syncTranslations(string $id, array $tr): void
    {
        $t        = $this->translations;
        $byLocale = [];

        foreach ($tr as $field => $values) {
            foreach ($values as $locale => $value) {
                $byLocale[$locale][$field] = $value;
            }
        }

        foreach ($byLocale as $locale => $values) {
            $where  = [$t['fk'] => $id, $t['locale'] => $locale];
            $exists = $this->db->table($t['table'])->where($where)->countAllResults() > 0;
            $empty  = implode('', array_map('strval', $values)) === '';

            if ($exists) {
                $empty && ! in_array($locale, $t['required'], true)
                    ? $this->db->table($t['table'])->where($where)->delete()
                    : $this->db->table($t['table'])->where($where)->update($values);
            } elseif (! $empty) {
                $this->db->table($t['table'])->insert($where + $values);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function deleteRecord(string $id, array $row, bool $purge): void
    {
        $result = $this->callHook('beforeDelete', $id, $row, $purge);

        if ($result === false || is_string($result)) {
            throw new ApiException(409, 'operation-failed', is_string($result) ? $result : '');
        }

        if ($purge || ! $this->softDeletes) {
            $this->db->transStart();

            foreach ($this->relationsNN as $nn) {
                $this->db->table($nn['junction'])->where($nn['thisKey'], $id)->delete();
            }

            if ($this->translations !== null) {
                $this->db->table($this->translations['table'])->where($this->translations['fk'], $id)->delete();
            }

            $this->db->table($this->table)->where($this->primaryKey, $id)->delete();
            $this->db->transComplete();

            foreach ($this->types as $field => $type) {
                if (in_array($type['type'], ['upload', 'image'], true) && ! empty($row[$field]) && ($type['options']['deleteFiles'] ?? true)) {
                    $this->removeFile($field, (string) $row[$field]);
                }
            }
        } else {
            $this->db->table($this->table)->where($this->primaryKey, $id)->update([$this->deletedField => date('Y-m-d H:i:s')]);
        }

        $this->callHook('afterDelete', $id, $row, $purge);
        $this->callHook('afterWrite', 'delete', $id, [], $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function restoreRecord(string $id, array $row): void
    {
        if ($row[$this->deletedField] === null) {
            return;
        }

        $this->db->table($this->table)->where($this->primaryKey, $id)->update([$this->deletedField => null]);
        $this->callHook('afterRestore', $id, $row);
        $this->callHook('afterWrite', 'restore', $id, [], $row);
    }

    protected function callHook(string $name, mixed ...$args): mixed
    {
        return isset($this->hooks[$name]) ? ($this->hooks[$name])(...$args) : null;
    }

    // ------------------------------------------------------------------
    // Fitxers
    // ------------------------------------------------------------------

    protected function uploadOperation(string $id, string $field): ResponseInterface
    {
        if (! in_array($this->typeOf($field), ['upload', 'image'], true) || ! in_array($field, $this->inputFields('edit'), true)) {
            throw ApiException::notFound();
        }

        $old = $this->findRecord($id) ?? throw ApiException::notFound();

        if (! $this->can('edit', $old)) {
            throw ApiException::forbidden();
        }

        $file = $this->request->getFile($field) ?? $this->request->getFile('file');

        if (! $file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw ApiException::validation([$field => [lang('Kpapy.fileMissing')]]);
        }

        if (($message = $this->checkUpload($field, $file)) !== null) {
            throw ApiException::validation([$field => [$message]]);
        }

        $this->save('edit', $id, $old, [], [], [], [$field => $file]);

        return $this->itemResponse($this->findRecord($id) ?? throw ApiException::notFound(), 200);
    }

    /**
     * Descàrrega d'un fitxer privat amb una URL signada (fileUrl()).
     */
    protected function downloadOperation(string $id, string $field): ResponseInterface
    {
        if (! in_array($this->typeOf($field), ['upload', 'image'], true) || in_array($field, $this->hidden, true)) {
            throw ApiException::notFound();
        }

        $expires   = (int) $this->request->getGet('expires');
        $signature = (string) $this->request->getGet('signature');

        if ($expires < time() || ! hash_equals($this->fileSignature($id, $field, $expires), $signature)) {
            throw ApiException::forbidden(lang('Kpapy.invalidSignature'));
        }

        $row  = $this->findRecord($id) ?? throw ApiException::notFound();
        $path = (string) ($row[$field] ?? '');
        $file = $this->uploadDir($field) . '/' . $path;

        if ($path === '' || str_contains($path, '..') || ! is_file($file)) {
            throw ApiException::notFound();
        }

        return service('response')
            ->setHeader('Content-Type', mime_content_type($file) ?: 'application/octet-stream')
            ->setHeader('Content-Disposition', 'inline; filename="' . basename($path) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, max-age=' . max(0, $expires - time()))
            ->setBody((string) file_get_contents($file));
    }

    /**
     * URL d'un fitxer: pública si el camp té 'url', o signada i temporal si és privat.
     *
     * @param array<string, mixed> $row
     */
    protected function fileUrl(string $field, string $path, array $row): ?string
    {
        if ($path === '') {
            return null;
        }

        $options = $this->typeOptions($field);

        if (isset($options['url'])) {
            return rtrim((string) $options['url'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        }

        $id      = (string) $row[$this->primaryKey];
        $expires = time() + $this->config->fileUrlLifetime;
        $expires -= $expires % 60;   // la mateixa URL durant un minut: el client la pot desar en memòria cau

        return $this->url($id, 'files/' . $field) . '?' . http_build_query(['expires' => $expires, 'signature' => $this->fileSignature($id, $field, $expires)]);
    }

    protected function fileSignature(string $id, string $field, int $expires): string
    {
        $key = Secret::key($this->config, 'api-file');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $this->getResource() . "\0{$id}\0{$field}\0{$expires}", $key, true)), '+/', '-_'), '=');
    }

    protected function uploadDir(string|false $field): string
    {
        $options = $field === false ? [] : $this->typeOptions($field);

        return rtrim((string) ($options['path'] ?? $this->config->uploadPath . '/' . $this->table), '/\\');
    }

    protected function checkUpload(string $field, UploadedFile $file): ?string
    {
        $options = $this->typeOptions($field);
        $allowed = (array) ($options['allowed'] ?? ($this->typeOf($field) === 'image' ? $this->config->imageAllowed : $this->config->uploadAllowed));
        $maxSize = (int) ($options['maxSize'] ?? $this->config->uploadMaxSize);

        if (! $file->isValid()) {
            return $file->getErrorString();
        }

        $extension = strtolower($file->getClientExtension());
        $guessed   = strtolower((string) $file->guessExtension());

        if (! in_array($extension, $allowed, true) || ($guessed !== '' && ! in_array($guessed, $allowed, true) && ! ($guessed === 'jpg' && $extension === 'jpeg'))) {
            return lang('Kpapy.fileType', [implode(', ', $allowed)]);
        }

        if ($file->getSizeByUnit('kb') > $maxSize) {
            return lang('Kpapy.fileSize', [$maxSize]);
        }

        if ($this->typeOf($field) === 'image' && ! str_starts_with((string) $file->getMimeType(), 'image/')) {
            return lang('Kpapy.fileType', [implode(', ', $allowed)]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function storeUpload(string $field, UploadedFile $file, array $data): string
    {
        $options = $this->typeOptions($field);
        $subdir  = $options['subdir'] ?? '';

        if ($subdir instanceof Closure) {
            $subdir = (string) $subdir($data);
        }

        $subdir = trim(preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) ($data[$m[1]] ?? ''), (string) $subdir) ?? '', '/');

        if ($subdir !== '' && ! preg_match('#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*$#', $subdir)) {
            $subdir = '';
        }

        $dir = $this->uploadDir($field) . ($subdir === '' ? '' : '/' . $subdir);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new ApiException(500, 'server-error', lang('Kpapy.exceptions.cannotCreateDir', [$dir]));
        }

        $name = $file->getRandomName();
        $file->move($dir, $name);

        return ($subdir === '' ? '' : $subdir . '/') . $name;
    }

    protected function removeFile(string $field, string $path): void
    {
        if ($path === '' || str_contains($path, '..')) {
            return;
        }

        $root = realpath($this->uploadDir($field));
        $file = realpath($this->uploadDir($field) . '/' . $path);

        if ($root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)) {
            @unlink($file);
        }
    }
}
