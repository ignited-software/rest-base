<?php

namespace Ignited\RestBase;

use Illuminate\Support\Str;

/**
 * The OpenAPI 3.1 document of the API, built from the registry alone (no database, no request), so the same
 * descriptors always give the same document: what is committed can be compared with what the code says.
 */
final class OpenApi
{
    private const ACTION_SUMMARY = [
        'index' => 'List %s',
        'show' => 'Read one of %s',
        'create' => 'Create one of %s',
        'update' => 'Change one of %s',
        'destroy' => 'Delete one of %s',
    ];

    public function __construct(private readonly Registry $registry) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $paths = [];
        $schemas = $this->sharedSchemas();
        $tags = [];

        foreach ($this->registry->all() as $slug => $d) {
            $resource = $this->registry->resource($slug);
            $name = self::schemaName($slug);
            $tags[] = array_filter(['name' => $slug, 'description' => $d->description ?? $d->label]);
            $schemas[$name] = $this->recordSchema($d, true);
            $schemas["{$name}Summary"] = $this->recordSchema($d, false);
            if ($d->has('create')) {
                $schemas["{$name}Create"] = $this->writeSchema($d, 'create');
            }
            if ($d->has('update')) {
                $schemas["{$name}Update"] = $this->writeSchema($d, 'update');
            }

            foreach ($d->operations as $operation) {
                $path = "/{$slug}/{$operation->path}";
                $paths[$path][strtolower($operation->method)] = $resource->openApi($operation->name, $this->customOperation($d, $operation));
            }
            if ($d->has('index')) {
                $paths["/{$slug}"]['get'] = $resource->openApi('index', $this->operation($d, 'index', [
                    'parameters' => $this->indexParameters($d),
                    'responses' => ['200' => $this->jsonResponse('A page of records.', [
                        'type' => 'object',
                        'required' => ['data', 'meta', 'links'],
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/{$name}Summary"]],
                            'meta' => ['$ref' => '#/components/schemas/PageMeta'],
                            'links' => ['$ref' => '#/components/schemas/PageLinks'],
                        ],
                    ])] + $this->errors([401, 403, 422, 429]),
                ]));
            }
            if ($d->has('create')) {
                $paths["/{$slug}"]['post'] = $resource->openApi('create', $this->operation($d, 'create', [
                    'parameters' => [$this->fieldsParameter($d)],
                    'requestBody' => $this->body("{$name}Create"),
                    'responses' => ['201' => $this->jsonResponse('Created; Location names the new record.', $this->wrapped($name), [
                        'Location' => ['description' => 'The new record\'s URL.', 'schema' => ['type' => 'string']],
                    ])] + $this->errors([400, 401, 403, 422, 429]),
                ]));
            }
            $member = "/{$slug}/{id}";
            $idParameter = $this->idParameter($d);
            if ($d->has('show')) {
                $paths[$member]['get'] = $resource->openApi('show', $this->operation($d, 'show', [
                    'parameters' => [$idParameter, ...$this->showParameters($d)],
                    'responses' => ['200' => $this->jsonResponse('The record, with its belongs-to records embedded.', $this->wrapped($name))] + $this->errors([401, 403, 404, 422, 429]),
                ]));
            }
            if ($d->has('update')) {
                $paths[$member]['patch'] = $resource->openApi('update', $this->operation($d, 'update', [
                    'description' => 'A partial merge: attributes left out keep their value; send null to clear one. PUT is an alias with the same meaning.',
                    'parameters' => [$idParameter, $this->fieldsParameter($d)],
                    'requestBody' => $this->body("{$name}Update"),
                    'responses' => ['200' => $this->jsonResponse('The changed record.', $this->wrapped($name))] + $this->errors([400, 401, 403, 404, 422, 429]),
                ]));
            }
            if ($d->has('destroy')) {
                $paths[$member]['delete'] = $resource->openApi('destroy', $this->operation($d, 'destroy', [
                    'description' => 'Refused with 422 has_dependent_records while other records depend on this one; nothing cascades.',
                    'parameters' => [$idParameter],
                    'responses' => ['204' => ['description' => 'Deleted.']] + $this->errors([401, 403, 404, 422, 429]),
                ]));
            }
        }

        return [
            'openapi' => '3.1.0',
            'info' => array_filter([
                'title' => (string) config('rest-base.openapi.title', 'API'),
                'version' => (string) config('rest-base.openapi.version', '1.0.0'),
                'description' => config('rest-base.openapi.description'),
            ]),
            'servers' => [['url' => '/'.trim((string) config('rest-base.prefix', 'api/v2'), '/')]],
            'tags' => $tags,
            'paths' => $paths ?: (object) [],
            'components' => array_filter([
                'schemas' => $schemas,
                'responses' => $this->errorResponses(),
                'parameters' => [
                    'page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
                ],
                'securitySchemes' => config('rest-base.openapi.security_schemes') ?: null,
            ]),
        ];
    }

    public function json(): string
    {
        return json_encode($this->build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    public static function schemaName(string $slug): string
    {
        return Str::studly(Str::singular($slug));
    }

    /** @return array<string, mixed> */
    private function operation(Descriptor $d, string $action, array $operation): array
    {
        $label = $d->label ?? Str::headline($d->slug);
        $base = [
            'tags' => [$d->slug],
            'operationId' => Str::camel($action.'-'.$d->slug),
            'summary' => sprintf(self::ACTION_SUMMARY[$action], strtolower($label)),
        ];

        return $this->secure($d, $action, $base + $operation);
    }

    /** @return array<string, mixed> */
    private function customOperation(Descriptor $d, Operation $operation): array
    {
        $responses = [];
        foreach ($operation->responses as $status => $schema) {
            $responses[(string) $status] = $this->jsonResponse($schema['description'] ?? 'OK.', $schema['schema'] ?? ['type' => 'object']);
        }

        return $this->secure($d, $operation->name, array_filter([
            'tags' => [$d->slug],
            'operationId' => Str::camel($operation->name.'-'.$d->slug),
            'summary' => $operation->summary,
            'description' => $operation->description,
            'parameters' => str_contains($operation->path, '{id}') ? [$this->idParameter($d)] : null,
            'requestBody' => $operation->request !== null ? ['required' => true, 'content' => ['application/json' => ['schema' => $operation->request]]] : null,
            'responses' => ($responses ?: ['200' => ['description' => 'OK.']]) + $this->errors([401, 403, 422, 429]),
        ]));
    }

    /** The security requirement, and who may call it: `x-roles` and a line in the description. */
    private function secure(Descriptor $d, string $action, array $operation): array
    {
        $schemes = array_keys((array) config('rest-base.openapi.security_schemes', []));
        if ($schemes !== []) {
            $operation['security'] = array_map(fn ($s) => [$s => []], $schemes);
        }
        $roles = $d->rolesFor($action);
        if ($roles !== null) {
            $operation['x-roles'] = $roles;
            $who = $roles === [] ? 'Nobody (switched off).' : 'Callable by: '.implode(', ', $roles).'.';
            $operation['description'] = trim(($operation['description'] ?? '')."\n\n".$who);
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    private function recordSchema(Descriptor $d, bool $detailed): array
    {
        $properties = [];
        foreach ($d->visible($detailed) as $attribute) {
            $properties[$attribute->name] = $attribute->schema();
        }
        if ($detailed) {
            foreach ($d->relationships as $name => $relation) {
                $ref = ['$ref' => '#/components/schemas/'.self::schemaName($relation->resource).'Summary'];
                $properties[$name] = $relation->isToOne()
                    ? ['oneOf' => [$ref, ['type' => 'null']], 'description' => 'Embedded on show.']
                    : ['type' => 'array', 'items' => $ref, 'description' => "Only with ?include={$name}."];
            }
        }
        $properties['links'] = [
            'type' => 'object',
            'description' => 'self, and a URL per relationship: the record of a belongs-to, the filtered list of a has-many.',
            'additionalProperties' => ['type' => 'string'],
        ];

        return array_filter([
            'type' => 'object',
            'description' => $detailed ? null : 'The list form: no show-only attributes, no embedded records.',
            'properties' => $properties,
        ]);
    }

    /** @return array<string, mixed> */
    private function writeSchema(Descriptor $d, string $action): array
    {
        $properties = [];
        $required = [];
        foreach ($d->writable($action) as $attribute) {
            $schema = $attribute->schema();
            unset($schema['readOnly']);
            $properties[$attribute->name] = $schema;
            if ($action === 'create' && $attribute->required) {
                $required[] = $attribute->name;
            }
        }

        return array_filter([
            'type' => 'object',
            'description' => 'Any other key is refused with 422 (unknown_attribute or not_writable).',
            'required' => $required ?: null,
            'properties' => $properties ?: (object) [],
            'additionalProperties' => false,
        ], fn ($v) => $v !== null);
    }

    /** @return list<array<string, mixed>> */
    private function indexParameters(Descriptor $d): array
    {
        $parameters = [];
        foreach ($d->filters as $name => $range) {
            $schema = $d->attributes[$name]->schema();
            unset($schema['readOnly'], $schema['description']);
            $parameters[] = ['name' => "filter[{$name}]", 'in' => 'query', 'description' => 'Equal to; a comma-separated list means any of.', 'schema' => ['type' => 'string']];
            if ($range) {
                foreach (array_keys(Descriptor::RANGE_OPERATORS) as $op) {
                    $parameters[] = ['name' => "filter[{$name}][{$op}]", 'in' => 'query', 'schema' => array_diff_key($schema, ['enum' => 1])];
                }
            }
        }
        if ($d->sorts !== []) {
            $parameters[] = ['name' => 'sort', 'in' => 'query', 'description' => 'Comma-separated; prefix - for descending. Sortable: '.implode(', ', $d->sorts).'.'
                .($d->defaultSort ? " Default: {$d->defaultSort}." : ''), 'schema' => ['type' => 'string']];
        }
        $parameters[] = $this->fieldsParameter($d);
        $parameters[] = ['$ref' => '#/components/parameters/page'];
        $parameters[] = ['name' => 'per_page', 'in' => 'query', 'description' => 'Above the maximum it is lowered to the maximum.', 'schema' => [
            'type' => 'integer', 'minimum' => 1,
            'default' => $d->perPage ?? (int) config('rest-base.pagination.per_page', 100),
            'maximum' => $d->maxPerPage ?? (int) config('rest-base.pagination.max_per_page', 1000),
        ]];

        return $parameters;
    }

    /** @return list<array<string, mixed>> */
    private function showParameters(Descriptor $d): array
    {
        $parameters = [$this->fieldsParameter($d)];
        $many = array_keys(array_filter($d->relationships, fn (Relationship $r) => ! $r->isToOne()));
        if ($many !== []) {
            $parameters[] = ['name' => 'include', 'in' => 'query', 'description' => 'Comma-separated has-many relationships to embed: '.implode(', ', $many).'.', 'schema' => ['type' => 'string']];
        }

        return $parameters;
    }

    /** @return array<string, mixed> */
    private function idParameter(Descriptor $d): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => $d->keyType === 'integer' ? 'integer' : 'string']];
    }

    /** @return array<string, mixed> */
    private function fieldsParameter(Descriptor $d): array
    {
        return ['name' => "fields[{$d->slug}]", 'in' => 'query', 'description' => 'Sparse fieldset (id is always kept); fields[<type>] works for every type in the answer.', 'schema' => ['type' => 'string']];
    }

    /** @return array<string, mixed> */
    private function body(string $schema): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => [
            'type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => "#/components/schemas/{$schema}"]],
        ]]]];
    }

    /** @return array<string, mixed> */
    private function wrapped(string $schema): array
    {
        return ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => "#/components/schemas/{$schema}"]]];
    }

    /** @return array<string, mixed> */
    private function jsonResponse(string $description, array $schema, array $headers = []): array
    {
        return array_filter(['description' => $description, 'headers' => $headers ?: null, 'content' => ['application/json' => ['schema' => $schema]]]);
    }

    /** @return array<string, array<string, mixed>> */
    private function errors(array $statuses): array
    {
        $out = [];
        foreach ($statuses as $status) {
            $out[(string) $status] = ['$ref' => "#/components/responses/Error{$status}"];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function errorResponses(): array
    {
        $out = [];
        foreach ([400, 401, 403, 404, 422, 429] as $status) {
            $out["Error{$status}"] = $this->jsonResponse(self::errorText($status), ['$ref' => '#/components/schemas/Error']);
        }

        return $out;
    }

    private static function errorText(int $status): string
    {
        return [400 => 'Unparseable JSON, or no data wrapper.', 401 => 'No valid token.', 403 => 'This caller may not do this.', 404 => 'No such record.',
            422 => 'Refused: error.reason says why.', 429 => 'Too many requests; see Retry-After.'][$status] ?? 'Error.';
    }

    /** @return array<string, mixed> */
    private function sharedSchemas(): array
    {
        $nullableUrl = ['type' => ['string', 'null']];

        return [
            'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => ['error' => [
                'type' => 'object',
                'required' => ['status', 'code', 'reason', 'message', 'details'],
                'properties' => [
                    'status' => ['type' => 'integer'],
                    'code' => ['type' => 'string', 'enum' => array_values(ApiException::CODES)],
                    'reason' => ['type' => ['string', 'null'], 'description' => 'unknown_parameter, unknown_filter, invalid_filter_operator, invalid_filter_value, unknown_sort, unknown_field, unknown_include, include_not_supported_on_index, invalid_page, invalid_json, missing_data, invalid_data, unknown_attribute, not_writable, nested_write_not_supported, invalid_attributes, duplicate, refused, constraint_violation, has_dependent_records, role_not_allowed; null for a generic error.'],
                    'message' => ['type' => 'string'],
                    'details' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
            ]]],
            'PageMeta' => ['type' => 'object', 'required' => ['page', 'per_page', 'total', 'total_pages'], 'properties' => [
                'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'total_pages' => ['type' => 'integer'],
            ]],
            'PageLinks' => ['type' => 'object', 'required' => ['self', 'first', 'prev', 'next', 'last'], 'properties' => [
                'self' => ['type' => 'string'], 'first' => ['type' => 'string'], 'prev' => $nullableUrl, 'next' => $nullableUrl, 'last' => ['type' => 'string'],
            ]],
        ];
    }
}
