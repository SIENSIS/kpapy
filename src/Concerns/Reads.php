<?php

declare(strict_types=1);

namespace Kpapy\Concerns;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DateTimeZone;
use Kpapy\Http\ApiException;
use Throwable;

/**
 * Lectura: llistat (filtres, cerca, ordenació, paginació), detall i format de sortida.
 *
 * @mixin \Kpapy\Resource
 */
trait Reads
{
    /**
     * Idioma dels camps traduïts per a aquesta petició (null = tots, amb ?lang=*).
     */
    protected string|false|null $requestLocale = false;

    // ------------------------------------------------------------------
    // Operacions
    // ------------------------------------------------------------------

    protected function listOperation(): ResponseInterface
    {
        if (! $this->can('list')) {
            throw ApiException::forbidden();
        }

        $get     = $this->request->getGet();
        $trashed = $this->trashedMode($get['trashed'] ?? null);
        // ?fields= pot demanar qualsevol camp del detall; sense, els del llistat
        $fields  = ($get['fields'] ?? '') === '' ? $this->listOutputFields() : $this->requestedFields($this->outputFields(), $get['fields']);
        $include = $this->requestedIncludes($get['include'] ?? null);

        $builder = $this->selectBuilder($trashed);
        $this->applyFilters($builder, $get['filter'] ?? null);
        $this->applySearch($builder, $get['q'] ?? null);

        $perPage = $this->perPageFrom($get['per_page'] ?? null);
        $meta    = ['per_page' => $perPage];
        $links   = [];

        if ($this->cursor !== null) {
            [$rows, $next] = $this->cursorPage($builder, $get['cursor'] ?? null, $perPage);
            $meta['next_cursor'] = $next;
            $links['self']       = $this->listLink([]);
            $links['next']       = $next === null ? null : $this->listLink(['cursor' => $next]);
        } else {
            $page  = max(1, (int) ($get['page'] ?? 1));
            $total = $this->withTotal ? $builder->countAllResults(false) : null;

            $this->applyOrder($builder, $get['sort'] ?? null);
            $rows = $builder->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

            $meta['page'] = $page;

            if ($total !== null) {
                $pages         = max(1, (int) ceil($total / $perPage));
                $meta['total'] = $total;
                $meta['pages'] = $pages;
            }

            $links = [
                'self'  => $this->listLink(['page' => $page]),
                'first' => $this->listLink(['page' => 1]),
                'prev'  => $page > 1 ? $this->listLink(['page' => $page - 1]) : null,
                'next'  => ($total === null ? count($rows) === $perPage : $page < ($pages ?? 1)) ? $this->listLink(['page' => $page + 1]) : null,
            ];

            if ($total !== null) {
                $links['last'] = $this->listLink(['page' => $pages ?? 1]);
            }
        }

        $items    = $this->formatRows($rows, $fields, $include);
        $response = $this->responder->collection($items, $meta, $links);

        return $this->conditional($response);
    }

    protected function showOperation(string $id): ResponseInterface
    {
        $trashed = $this->trashedMode($this->request->getGet('trashed'));
        $row     = $this->findRecord($id, $trashed);

        if ($row === null || ! $this->can('show', $row)) {
            throw $row === null ? ApiException::notFound() : ApiException::forbidden();
        }

        return $this->itemResponse($row, 200);
    }

    /**
     * Resposta amb un registre (detall, alta, edició, restauració).
     *
     * @param array<string, mixed> $row
     */
    protected function itemResponse(array $row, int $status, array $headers = []): ResponseInterface
    {
        $get     = $this->request->getGet();
        $fields  = $this->requestedFields($this->outputFields(), $get['fields'] ?? null);
        $include = $this->requestedIncludes($get['include'] ?? null);
        $item    = $this->formatRows([$row], $fields, $include)[0];
        $id      = (string) $row[$this->primaryKey];

        $response = $this->responder->item($item, ['self' => $this->url($id)], [], $status)
            ->setHeader('ETag', $this->etagFor($row));

        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $status === 200 ? $this->conditional($response) : $response;
    }

    // ------------------------------------------------------------------
    // Consultes
    // ------------------------------------------------------------------

    /**
     * Consulta amb l'abast del recurs (where() i paperera).
     *
     * @param string $trashed 'none' (sense la paperera), 'with' o 'only'
     */
    protected function baseBuilder(string $trashed = 'none'): BaseBuilder
    {
        $builder = $this->db->table($this->table);

        foreach ($this->wheres as [$field, $value]) {
            $column = str_contains($field, '.') || str_contains($field, ' ') ? $field : $this->table . '.' . $field;
            $builder->where($column, $value);
        }

        // Condicions pròpies (where(Closure)): entre parèntesis, perquè un OR no n'escapi.
        // Una closure que no afegeix res no deixa un grup buit "()" (SQL no vàlid).
        $conditions = static fn (BaseBuilder $builder): int => (fn (): int => count($this->QBWhere))->call($builder);

        foreach ($this->whereCallbacks as $callback) {
            $before = $conditions($builder);
            $builder->groupStart();
            $callback($builder, $this);

            if ($conditions($builder) === $before + 1) {
                (function (): void {
                    array_pop($this->QBWhere);
                    $this->QBWhereGroupStarted = false;
                    $this->QBWhereGroupCount--;
                })->call($builder);

                continue;
            }

            $builder->groupEnd();
        }

        if ($this->softDeletes) {
            match ($trashed) {
                'with'  => null,
                'only'  => $builder->where($this->table . '.' . $this->deletedField . ' IS NOT NULL'),
                default => $builder->where($this->table . '.' . $this->deletedField, null),
            };
        }

        return $builder;
    }

    /**
     * Consulta del llistat: també uneix la traducció de l'idioma de la petició perquè es
     * pugui cercar, filtrar i ordenar pels camps traduïts.
     */
    protected function selectBuilder(string $trashed = 'none'): BaseBuilder
    {
        $builder = $this->baseBuilder($trashed)->select($this->table . '.*');

        if ($this->translations !== null) {
            $tr     = $this->translations;
            $locale = $this->locale() ?? $this->localeChain()[0];

            $builder->join(
                $tr['table'] . ' kt',
                'kt.' . $tr['fk'] . ' = ' . $this->table . '.' . $this->primaryKey . ' AND kt.' . $tr['locale'] . ' = ' . $this->db->escape($locale),
                'left',
            );
        }

        return $builder;
    }

    /**
     * Registre de l'abast del recurs, o null.
     *
     * @return array<string, mixed>|null
     */
    protected function findRecord(string $id, string $trashed = 'none'): ?array
    {
        if (! $this->isValidId($id)) {
            return null;
        }

        return $this->baseBuilder($trashed)
            ->select($this->table . '.*')
            ->where($this->table . '.' . $this->primaryKey, $id)
            ->get()
            ->getRowArray();
    }

    protected function column(string $field): string
    {
        return $this->isTranslated($field) ? 'kt.' . $field : $this->table . '.' . $field;
    }

    protected function trashedMode(mixed $value): string
    {
        if ($value === null || $value === '' || ! $this->softDeletes) {
            return 'none';
        }

        if (! in_array($value, ['with', 'only'], true)) {
            throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['trashed']));
        }

        if (! $this->can('trash')) {
            throw ApiException::forbidden();
        }

        return $value;
    }

    protected function perPageFrom(mixed $value): int
    {
        $default = $this->perPage ?? $this->config->perPage;

        if ($value === null || $value === '') {
            return min($default, $this->config->maxPerPage);
        }

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['per_page']));
        }

        return min((int) $value, $this->config->maxPerPage);
    }

    // ------------------------------------------------------------------
    // Filtres, cerca i ordenació
    // ------------------------------------------------------------------

    protected function applyFilters(BaseBuilder $builder, mixed $filters): void
    {
        if ($filters === null || $filters === '' || $filters === []) {
            return;
        }

        if (! is_array($filters)) {
            throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['filter']));
        }

        if (! $this->can('filters')) {
            throw ApiException::forbidden();
        }

        $allowed = $this->filterFieldList();

        foreach ($filters as $field => $condition) {
            $field = (string) $field;

            if (! in_array($field, $allowed, true)) {
                throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field]));
            }

            foreach (is_array($condition) ? $condition : ['eq' => $condition] as $operator => $value) {
                $this->applyFilter($builder, $field, (string) $operator, $value);
            }
        }
    }

    protected function applyFilter(BaseBuilder $builder, string $field, string $operator, mixed $value): void
    {
        $column = $this->column($field);
        $type   = $this->typeOf($field);

        if ($type === 'relation_nn') {
            $nn  = $this->relationsNN[$field];
            $ids = array_map(fn ($v) => $this->filterValue($field, $v), $this->listValue($value));

            $builder->whereIn($this->table . '.' . $this->primaryKey, static function (BaseBuilder $sub) use ($nn, $ids): BaseBuilder {
                return $sub->select($nn['thisKey'])->from($nn['junction'])->whereIn($nn['otherKey'], $ids);
            });

            return;
        }

        if ($type === 'multiselect') {
            foreach ($this->listValue($value) as $item) {
                $item = (string) $this->filterValue($field, $item);
                ($this->typeOptions($field)['storage'] ?? 'json') === 'csv'
                    ? $builder->where("(',' || " . $this->db->protectIdentifiers($column) . " || ',') LIKE " . $this->db->escape('%,' . $this->db->escapeLikeString($item) . ',%'), null, false)
                    : $builder->like($column, json_encode($item), 'both', null, false);
            }

            return;
        }

        switch ($operator) {
            case 'eq':
                $builder->where($column, $this->filterValue($field, $value));
                break;

            case 'ne':
                $builder->where($column . ' !=', $this->filterValue($field, $value));
                break;

            case 'lt':
            case 'lte':
            case 'gt':
            case 'gte':
                $sign = ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='][$operator];
                $builder->where($column . ' ' . $sign, $this->filterValue($field, $value));
                break;

            case 'in':
                $builder->whereIn($column, array_map(fn ($v) => $this->filterValue($field, $v), $this->listValue($value)));
                break;

            case 'between':
                $values = $this->listValue($value);

                if (count($values) !== 2) {
                    throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field]));
                }

                $builder->where($column . ' >=', $this->filterValue($field, $values[0]))
                    ->where($column . ' <=', $this->filterValue($field, $values[1]));
                break;

            case 'like':
                $builder->like($column, (string) $this->scalar($field, $value), 'both', true);
                break;

            case 'null':
                $builder->where($column, null);
                break;

            case 'notnull':
                $builder->where($column . ' IS NOT NULL');
                break;

            default:
                throw ApiException::badRequest(lang('Kpapy.invalidOperator', [$operator]));
        }
    }

    /**
     * @return list<mixed>
     */
    protected function listValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn ($v): bool => $v !== ''));
    }

    protected function scalar(string $field, mixed $value): string
    {
        if (! is_scalar($value)) {
            throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field]));
        }

        return (string) $value;
    }

    /**
     * Converteix i valida el valor d'un filtre segons el tipus del camp.
     */
    protected function filterValue(string $field, mixed $value): mixed
    {
        $value = $this->scalar($field, $value);
        $type  = $this->typeOf($field);

        if (isset($this->relations[$field]) || $type === 'relation_nn') {
            $context = isset($this->relations[$field]) ? $this->relations[$field]['table'] : $this->relationsNN[$field]['table'];
            $decoded = $this->decodeId($value, $context);

            if ($decoded === null) {
                throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field]));
            }

            return $decoded;
        }

        if ($field === $this->primaryKey) {
            return $this->decodeId($value) ?? throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field]));
        }

        return match ($type) {
            'int'      => preg_match('/^-?\d+$/', $value) ? (int) $value : throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field])),
            'float', 'decimal' => is_numeric($value) ? $value : throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field])),
            'bool'     => in_array(strtolower($value), ['1', 'true', 'yes'], true) ? 1 : (in_array(strtolower($value), ['0', 'false', 'no'], true) ? 0 : throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field]))),
            'date'     => $this->parseDate($value, 'Y-m-d') ?? throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field])),
            'datetime' => $this->parseDate($value, 'Y-m-d H:i:s') ?? throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field])),
            'enum', 'multiselect' => array_key_exists($value, (array) ($this->typeOptions($field)['options'] ?? [])) ? $value : throw ApiException::badRequest(lang('Kpapy.invalidFilter', [$field])),
            default    => $value,
        };
    }

    protected function applySearch(BaseBuilder $builder, mixed $query): void
    {
        if ($query === null || $query === '') {
            return;
        }

        if (! is_string($query)) {
            throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['q']));
        }

        if (! $this->can('search')) {
            throw ApiException::forbidden();
        }

        $fields = $this->searchFieldList();

        if ($fields === []) {
            return;
        }

        $builder->groupStart();

        foreach ($fields as $field) {
            $builder->orLike($this->column($field), trim($query), 'both', true);
        }

        $builder->groupEnd();
    }

    protected function applyOrder(BaseBuilder $builder, mixed $sort): void
    {
        $order = [];

        if ($sort !== null && $sort !== '') {
            if (! is_string($sort)) {
                throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['sort']));
            }

            $sortable = $this->sortableFields();

            foreach (array_filter(array_map('trim', explode(',', $sort))) as $item) {
                $field = ltrim($item, '-+');

                if (! in_array($field, $sortable, true)) {
                    throw ApiException::badRequest(lang('Kpapy.invalidSort', [$field]));
                }

                $order[] = [$field, str_starts_with($item, '-') ? 'DESC' : 'ASC'];
            }
        } elseif ($this->defaultOrder !== null) {
            $order[] = [$this->defaultOrder[0], strtoupper($this->defaultOrder[1])];
        }

        foreach ($order as [$field, $direction]) {
            $builder->orderBy($this->column($field), $direction);
        }

        // Ordre estable entre pàgines
        $builder->orderBy($this->table . '.' . $this->primaryKey, $order === [] ? 'ASC' : $order[count($order) - 1][1]);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: string|null} [files, cursor següent]
     */
    protected function cursorPage(BaseBuilder $builder, mixed $cursor, int $perPage): array
    {
        [$field, $direction] = $this->cursor;

        $column = $this->column($field);
        $pk     = $this->table . '.' . $this->primaryKey;
        $sign   = $direction === 'desc' ? '<' : '>';

        if (is_string($cursor) && $cursor !== '') {
            $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

            if (! is_array($decoded) || count($decoded) !== 2) {
                throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['cursor']));
            }

            [$value, $code] = $decoded;

            // La clau del cursor també va emmascarada
            $id = is_scalar($code) ? $this->decodeId((string) $code) : null;

            if ($id === null) {
                throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['cursor']));
            }

            $builder->groupStart()
                ->where("{$column} {$sign}", $value)
                ->orGroupStart()->where($column, $value)->where("{$pk} {$sign}", $id)->groupEnd()
                ->groupEnd();
        }

        $rows = $builder->orderBy($column, strtoupper($direction))->orderBy($pk, strtoupper($direction))
            ->limit($perPage + 1)->get()->getResultArray();

        $next = null;

        if (count($rows) > $perPage) {
            $rows = array_slice($rows, 0, $perPage);
            $last = $rows[$perPage - 1];
            $next = rtrim(strtr(base64_encode((string) json_encode([$last[$field] ?? null, $this->encodeId((string) $last[$this->primaryKey])])), '+/', '-_'), '=');
        }

        return [$rows, $next];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function listLink(array $params): string
    {
        $query = array_merge($this->request->getGet(), $params);

        if (isset($params['cursor'])) {
            unset($query['page']);
        }

        return $this->url() . ($query === [] ? '' : '?' . http_build_query($query));
    }

    // ------------------------------------------------------------------
    // Paràmetres de sortida
    // ------------------------------------------------------------------

    /**
     * @param list<string> $available
     *
     * @return list<string>
     */
    protected function requestedFields(array $available, mixed $param): array
    {
        if ($param === null || $param === '') {
            return $available;
        }

        if (! is_string($param)) {
            throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['fields']));
        }

        $fields = array_values(array_filter(array_map('trim', explode(',', $param))));

        foreach ($fields as $field) {
            if (! in_array($field, $available, true)) {
                throw ApiException::badRequest(lang('Kpapy.invalidField', [$field]));
            }
        }

        if (! in_array($this->primaryKey, $fields, true)) {
            array_unshift($fields, (string) $this->primaryKey);
        }

        return $fields;
    }

    /**
     * Relacions demanades amb ?include= (noms 'as' de les 1-N i noms de les N-N).
     *
     * @return list<string>
     */
    protected function requestedIncludes(mixed $param): array
    {
        if ($param === null || $param === '') {
            return [];
        }

        if (! is_string($param)) {
            throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['include']));
        }

        $available = [...array_column($this->relations, 'as'), ...array_keys($this->relationsNN)];
        $includes  = array_values(array_filter(array_map('trim', explode(',', $param))));

        foreach ($includes as $include) {
            if (! in_array($include, $available, true) || in_array($this->relationFieldFor($include), $this->hidden, true)) {
                throw ApiException::badRequest(lang('Kpapy.invalidInclude', [$include]));
            }
        }

        return $includes;
    }

    protected function relationFieldFor(string $include): string
    {
        foreach ($this->relations as $field => $relation) {
            if ($relation['as'] === $include) {
                return $field;
            }
        }

        return $include;
    }

    /**
     * Idioma dels camps traduïts: ?lang=xx, ?lang=* (tots: null) o Accept-Language.
     */
    protected function locale(): ?string
    {
        if ($this->requestLocale !== false) {
            return $this->requestLocale;
        }

        if ($this->translations === null) {
            return $this->requestLocale = null;
        }

        $param   = $this->request->getGet('lang');
        $locales = $this->translations['locales'];

        if ($param === '*') {
            return $this->requestLocale = null;
        }

        if (is_string($param) && $param !== '') {
            if (! in_array($param, $locales, true)) {
                throw ApiException::badRequest(lang('Kpapy.invalidParameter', ['lang']));
            }

            return $this->requestLocale = $param;
        }

        $accept = $this->request->getHeaderLine('Accept-Language');

        foreach (array_map(static fn (string $part): string => strtolower(trim(explode(';', $part)[0])), explode(',', $accept)) as $wanted) {
            foreach ([$wanted, explode('-', $wanted)[0]] as $candidate) {
                if (in_array($candidate, $locales, true)) {
                    return $this->requestLocale = $candidate;
                }
            }
        }

        return $this->requestLocale = $this->localeChain()[0];
    }

    /**
     * Ordre en què es busca un camp traduït que no hi és en l'idioma demanat.
     *
     * @return list<string>
     */
    protected function localeChain(?string $first = null): array
    {
        $locales = $this->translations['locales'] ?? [];
        $chain   = [];

        foreach ([$first, ...$this->config->localeFallbacks, config('App')->defaultLocale ?? null, ...$locales] as $locale) {
            if (is_string($locale) && in_array($locale, $locales, true) && ! in_array($locale, $chain, true)) {
                $chain[] = $locale;
            }
        }

        return $chain === [] ? $locales : $chain;
    }

    // ------------------------------------------------------------------
    // Format de sortida
    // ------------------------------------------------------------------

    /**
     * Converteix les files de la base de dades en els elements de la resposta.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $fields
     * @param list<string>               $include
     *
     * @return list<array<string, mixed>>
     */
    protected function formatRows(array $rows, array $fields, array $include): array
    {
        if ($rows === []) {
            return [];
        }

        $ids          = array_map(fn (array $row): string => (string) $row[$this->primaryKey], $rows);
        $related      = $this->loadRelated($rows, $include);
        $nn           = $this->loadNN($ids, array_intersect($fields, array_keys($this->relationsNN)), $include);
        $translations = array_intersect($fields, $this->translations['fields'] ?? []) !== [] ? $this->loadTranslations($ids) : [];
        $locale       = $this->locale();
        $items        = [];

        foreach ($rows as $row) {
            $id   = (string) $row[$this->primaryKey];
            $item = [];

            foreach ($fields as $field) {
                if (isset($this->fieldCallbacks[$field])) {
                    $item[$field] = ($this->fieldCallbacks[$field])($row[$field] ?? null, $row);

                    continue;
                }

                if (isset($this->relationsNN[$field])) {
                    $item[$field] = $nn[$field][$id] ?? [];

                    continue;
                }

                if ($this->isTranslated($field)) {
                    $item[$field] = $this->translatedValue($translations[$id] ?? [], $field, $locale);

                    continue;
                }

                $item[$field] = $this->outputValue($field, $row[$field] ?? null, $row);
            }

            foreach ($this->relations as $field => $relation) {
                if (in_array($relation['as'], $include, true)) {
                    $item[$relation['as']] = $related[$field][(string) ($row[$field] ?? '')] ?? null;
                }
            }

            $items[] = $this->transformer !== null ? ($this->transformer)($item, $row) : $item;
        }

        return $items;
    }

    /**
     * Valor de sortida d'un camp segons el seu tipus.
     *
     * @param array<string, mixed> $row
     */
    protected function outputValue(string $field, mixed $value, array $row): mixed
    {
        if ($field === $this->primaryKey) {
            return $value === null ? null : $this->encodeId((string) $value);
        }

        if (isset($this->relations[$field])) {
            return $value === null || $value === '' ? null : $this->encodeId((string) $value, $this->relations[$field]['table']);
        }

        if ($value === null) {
            return $this->typeOf($field) === 'multiselect' ? [] : null;
        }

        return match ($this->typeOf($field)) {
            'int'         => (int) $value,
            'float'       => (float) $value,
            'decimal'     => isset($this->typeOptions($field)['scale']) ? number_format((float) $value, (int) $this->typeOptions($field)['scale'], '.', '') : (string) $value,
            'bool'        => (bool) $value,
            'datetime'    => $this->isoDate((string) $value),
            'json'        => is_string($value) ? json_decode($value, true) : $value,
            'multiselect' => $this->decodeMulti($value),
            'upload', 'image' => $this->fileUrl($field, (string) $value, $row),
            default       => is_scalar($value) ? (string) $value : $value,
        };
    }

    protected function isoDate(string $value): ?string
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone(app_timezone())))->format(DATE_ATOM);
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Data enviada pel client (ISO 8601 o el format de la base de dades) en el format de la base de dades.
     */
    protected function parseDate(string $value, string $format): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone(app_timezone()));
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone(app_timezone()))->format($format);
    }

    /**
     * @return list<string>
     */
    protected function decodeMulti(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Files relacionades (1-N) que s'inclouen: camp => [id => objecte].
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $include
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function loadRelated(array $rows, array $include): array
    {
        $result = [];

        foreach ($this->relations as $field => $relation) {
            if (! in_array($relation['as'], $include, true)) {
                continue;
            }

            $ids = array_values(array_unique(array_filter(array_map(static fn (array $row) => $row[$field] ?? null, $rows), static fn ($v): bool => $v !== null && $v !== '')));

            if ($ids === []) {
                continue;
            }

            foreach ($this->db->table($relation['table'])->select($relation['columns'])->whereIn($relation['pk'], $ids)->get()->getResultArray() as $other) {
                $result[$field][(string) $other[$relation['pk']]] = $this->formatRelated($other, $relation);
            }
        }

        return $result;
    }

    /**
     * Valors de les relacions N-N: nom => [id => llista d'id o d'objectes].
     *
     * @param list<string> $ids
     * @param list<string> $names
     * @param list<string> $include
     *
     * @return array<string, array<string, list<mixed>>>
     */
    protected function loadNN(array $ids, array $names, array $include): array
    {
        $result = [];

        foreach ($names as $name) {
            $nn      = $this->relationsNN[$name];
            $objects = in_array($name, $include, true);
            $links   = $this->db->table($nn['junction'])->select([$nn['thisKey'], $nn['otherKey']])->whereIn($nn['thisKey'], $ids)->get()->getResultArray();
            $others  = [];

            if ($objects && $links !== []) {
                foreach ($this->db->table($nn['table'])->select($nn['columns'])->whereIn($nn['pk'], array_unique(array_column($links, $nn['otherKey'])))->get()->getResultArray() as $other) {
                    $others[(string) $other[$nn['pk']]] = $this->formatRelated($other, $nn);
                }
            }

            foreach ($links as $link) {
                $other = (string) $link[$nn['otherKey']];
                $result[$name][(string) $link[$nn['thisKey']]][] = $objects ? ($others[$other] ?? null) : $this->encodeId($other, $nn['table']);
            }

            foreach ($result[$name] ?? [] as $id => $values) {
                $result[$name][$id] = array_values(array_filter($values, static fn ($v): bool => $v !== null));
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $relation
     *
     * @return array<string, mixed>
     */
    protected function formatRelated(array $row, array $relation): array
    {
        $out = [];

        foreach ($relation['columns'] as $column) {
            $out[$column] = $column === $relation['pk'] ? $this->encodeId((string) $row[$column], $relation['table']) : $row[$column];
        }

        return $out;
    }

    /**
     * Traduccions dels registres: id => [locale => [camp => valor]].
     *
     * @param list<string> $ids
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function loadTranslations(array $ids): array
    {
        $tr     = $this->translations;
        $result = [];

        foreach ($this->db->table($tr['table'])->whereIn($tr['fk'], $ids)->get()->getResultArray() as $row) {
            $result[(string) $row[$tr['fk']]][(string) $row[$tr['locale']]] = $row;
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $byLocale
     */
    protected function translatedValue(array $byLocale, string $field, ?string $locale): mixed
    {
        if ($locale === null) {
            $all = [];

            foreach ($this->translations['locales'] as $code) {
                $all[$code] = $byLocale[$code][$field] ?? null;
            }

            return $all;
        }

        foreach ($this->localeChain($locale) as $code) {
            $value = $byLocale[$code][$field] ?? null;

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // ETag
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    protected function etagFor(array $row): string
    {
        return '"' . md5((string) json_encode($row)) . '"';
    }

    /**
     * GET condicional: 304 si l'ETag del client coincideix.
     */
    protected function conditional(ResponseInterface $response): ResponseInterface
    {
        $etag = $response->getHeaderLine('ETag');

        if ($etag === '') {
            $etag = '"' . md5((string) $response->getBody()) . '"';
            $response->setHeader('ETag', $etag);
        }

        $match = $this->request->getHeaderLine('If-None-Match');

        if ($match !== '' && in_array($etag, array_map('trim', explode(',', $match)), true)) {
            return $response->setStatusCode(304)->setBody('');
        }

        return $response;
    }
}
