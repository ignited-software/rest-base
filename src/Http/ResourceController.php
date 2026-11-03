<?php

namespace Ignited\RestBase\Http;

use Ignited\RestBase\ApiException;
use Ignited\RestBase\ApiResource;
use Ignited\RestBase\Descriptor;
use Ignited\RestBase\Registry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * index, show, create, update and destroy for every registered resource. Extend it (ApiResource::controller())
 * to change one action; the protected helpers are there to build on.
 */
class ResourceController
{
    public function __construct(protected readonly Registry $registry, protected readonly Roles $roles) {}

    public function index(Request $request): JsonResponse
    {
        [$d, $resource] = $this->resolve($request);
        $q = QueryParams::forIndex($request, $d, $this->registry);

        $query = $resource->query($request);
        $q->applyFilters($query, $d);
        $total = (clone $query)->count();
        $q->applySort($query);
        $rows = $query->forPage($q->page, $q->perPage)->get();
        $pages = max(1, (int) ceil($total / $q->perPage));
        $serializer = $this->serializer($request);

        $link = fn (int $page) => $request->fullUrlWithQuery(['page' => $page]);

        return $this->json([
            'data' => $rows->map(fn (Model $m) => $serializer->record($d, $m, false, $q))->all(),
            'meta' => ['page' => $q->page, 'per_page' => $q->perPage, 'total' => $total, 'total_pages' => $pages],
            'links' => [
                'self' => $link($q->page),
                'first' => $link(1),
                'prev' => $q->page > 1 ? $link(min($q->page - 1, $pages)) : null,
                'next' => $q->page < $pages ? $link($q->page + 1) : null,
                'last' => $link($pages),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        [$d, $resource] = $this->resolve($request);
        $q = QueryParams::forShow($request, $d, $this->registry);
        $model = $this->find($request, $d, $resource);
        $resource->authorize('show', $request, $model);

        return $this->json(['data' => $this->serializer($request)->record($d, $model, true, $q)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$d, $resource] = $this->resolve($request);
        $q = QueryParams::forWrite($request, $this->registry);
        $data = $this->object($this->body($request));
        $resource->authorize('create', $request, null);

        $model = $this->create($request, $d, $resource, $data);

        return $this->json(['data' => $this->serializer($request)->record($d, $model, true, $q)], 201)
            ->header('Location', $this->serializer($request)->url($d->slug, $model->getKey()));
    }

    public function update(Request $request): JsonResponse
    {
        [$d, $resource] = $this->resolve($request);
        $q = QueryParams::forWrite($request, $this->registry);
        $data = $this->object($this->body($request));
        $model = $this->find($request, $d, $resource);
        $resource->authorize('update', $request, $model);

        $attributes = $this->attributesFor($d, $data, 'update');
        $this->refuseDuplicates($d, $resource, $request, $attributes, $model);
        $resource->fill($model, $attributes, $request, 'update');
        $this->save($model);

        return $this->json(['data' => $this->serializer($request)->record($d, $model->refresh(), true, $q)]);
    }

    public function destroy(Request $request): Response
    {
        [$d, $resource] = $this->resolve($request);
        QueryParams::forWrite($request, $this->registry);
        $model = $this->find($request, $d, $resource);
        $resource->authorize('destroy', $request, $model);

        $dependents = [];
        foreach ($d->dependents as $relation) {
            $count = $model->{$relation}()->count();
            if ($count > 0) {
                $dependents[$relation] = ["{$count} record(s) still depend on this one."];
            }
        }
        if ($dependents !== []) {
            throw ApiException::invalid('has_dependent_records', "This {$d->slug} record still has dependent records; remove or move those first.", $dependents);
        }
        try {
            $model->delete();
        } catch (QueryException) {
            throw ApiException::invalid('has_dependent_records', "The database refused to delete this {$d->slug} record: other records still point at it.");
        } catch (\Throwable $e) {
            throw $this->refusal($e);
        }

        return response()->noContent();
    }

    /** @return array{0: Descriptor, 1: ApiResource} */
    protected function resolve(Request $request): array
    {
        $slug = (string) $request->route()->defaults['_rest_resource'];

        return [$this->registry->descriptor($slug), $this->registry->resource($slug)];
    }

    protected function find(Request $request, Descriptor $d, ApiResource $resource): Model
    {
        $id = (string) $request->route('id');

        return $resource->query($request)->whereKey($id)->first()
            ?? throw new ApiException(404, null, "No {$d->slug} record with id {$id}.");
    }

    /**
     * Creates one record from a body's `data` object: checked, filled, saved, read back.
     *
     * @param  array<string, mixed>  $data
     */
    protected function create(Request $request, Descriptor $d, ApiResource $resource, array $data): Model
    {
        $attributes = $this->attributesFor($d, $data, 'create');
        $this->refuseDuplicates($d, $resource, $request, $attributes, null);
        $model = $resource->newModel();
        $resource->fill($model, $attributes, $request, 'create');
        $this->save($model);

        return $model->refresh();
    }

    /** The parsed JSON body's `data`. Unparseable JSON or no `data` key: 400, the only 400s there are. */
    protected function body(Request $request): mixed
    {
        try {
            $body = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'invalid_json', 'The request body is not valid JSON.');
        }
        if (! is_array($body) || array_is_list($body) && $body !== [] || ! array_key_exists('data', $body)) {
            throw new ApiException(400, 'missing_data', 'Send the record as {"data": {...}}.');
        }

        return $body['data'];
    }

    /** @return array<string, mixed> */
    protected function object(mixed $data): array
    {
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiException::invalid('invalid_data', '`data` must be an object of attributes.');
        }

        return $data;
    }

    /**
     * The attributes a write may set, checked and converted. A key the descriptor does not know, or does not let
     * this action write, is refused by name: nothing is dropped silently.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributesFor(Descriptor $d, array $data, string $action): array
    {
        $refused = ['unknown_attribute' => [], 'not_writable' => [], 'nested_write_not_supported' => []];
        foreach (array_keys($data) as $key) {
            $attribute = $d->attributes[$key] ?? null;
            match (true) {
                $attribute === null && isset($d->relationships[$key]) => $refused['nested_write_not_supported'][$key] = ["{$key} is a relationship; write its foreign key instead."],
                $attribute === null => $refused['unknown_attribute'][$key] = ["{$d->slug} has no attribute {$key}."],
                ! $attribute->writableOn($action) => $refused['not_writable'][$key] = [$attribute->writable === 'on_create' ? "{$key} is only set when creating." : "{$key} is read-only."],
                default => null,
            };
        }
        foreach ($refused as $reason => $details) {
            if ($details !== []) {
                throw ApiException::invalid($reason, 'Some attributes cannot be written: '.implode(', ', array_keys($details)).'.', $details);
            }
        }

        $rules = [];
        foreach ($d->writable($action) as $attribute) {
            $rules[$attribute->name] = $attribute->rules($action);
        }
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw ApiException::invalid('invalid_attributes', 'Some attributes are not valid.', $validator->errors()->toArray());
        }

        $out = [];
        foreach ($d->writable($action) as $attribute) {
            if (array_key_exists($attribute->name, $data)) {
                $out[$attribute->name] = $attribute->fromInput($data[$attribute->name]);
            }
        }

        return $out;
    }

    /**
     * A create (or an update that changes a unique set) that would repeat a unique column set is a 422 `duplicate`.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function refuseDuplicates(Descriptor $d, ApiResource $resource, Request $request, array $attributes, ?Model $existing): void
    {
        $details = $this->duplicates($d, $resource, $attributes, $existing);
        if ($details !== []) {
            throw ApiException::invalid('duplicate', "A {$d->slug} record with these values exists already.", $details);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, list<string>>
     */
    protected function duplicates(Descriptor $d, ApiResource $resource, array $attributes, ?Model $existing): array
    {
        $details = [];
        foreach ($d->unique as $columns) {
            if ($existing !== null && array_intersect($columns, array_keys($attributes)) === []) {
                continue;
            }
            $query = $resource->model()::query();
            foreach ($columns as $column) {
                $query->where($column, $attributes[$column] ?? $existing?->getAttribute($column));
            }
            if ($existing !== null) {
                $query->whereKeyNot($existing->getKey());
            }
            if ($query->exists()) {
                $details[implode(',', $columns)] = ['Taken: '.implode(', ', array_map(fn ($c) => "{$c}=".json_encode($attributes[$c] ?? $existing?->getAttribute($c)), $columns)).'.'];
            }
        }

        return $details;
    }

    /** Saves, turning a model guard (a saving hook that throws) or a database constraint into a 422, not a 500. */
    protected function save(Model $model): void
    {
        try {
            $model->save();
        } catch (QueryException) {
            throw ApiException::invalid('constraint_violation', 'The database refused this write: a unique, foreign-key or required column constraint was violated.');
        } catch (\Throwable $e) {
            throw $this->refusal($e);
        }
    }

    /**
     * A model's own refusal (an exception of a class in config rest-base.refusals, thrown by a saving or deleting
     * hook) becomes a 422 `refused` with its message; anything else is rethrown as it was.
     */
    protected function refusal(\Throwable $e): \Throwable
    {
        foreach ((array) config('rest-base.refusals', [LogicException::class, InvalidArgumentException::class]) as $class) {
            if ($e instanceof $class) {
                return ApiException::invalid('refused', $e->getMessage());
            }
        }

        return $e;
    }

    protected function serializer(Request $request): Serializer
    {
        return new Serializer($this->registry, $request, $this->roles);
    }

    protected function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
