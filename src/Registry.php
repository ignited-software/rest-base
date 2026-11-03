<?php

namespace Ignited\RestBase;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The resources on the API, opt-in per model in config rest-base.resources (slug => ApiResource class). Every
 * descriptor is checked when the registry is built, so a mistake fails at boot (and in the test suite), not on a
 * request: no attribute may be secret, every writable attribute must be fillable, every name a filter, sort or
 * relationship uses must exist.
 */
final class Registry
{
    /** @var array<string, ApiResource> */
    private array $resources = [];

    /** @var array<string, Descriptor> */
    private array $descriptors = [];

    /** @param  array<string, class-string<ApiResource>>  $config */
    public function __construct(array $config)
    {
        foreach ($config as $slug => $class) {
            if (! is_string($slug) || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
                throw new LogicException('rest-base.resources: every resource needs a slug of lowercase letters, digits and dashes.');
            }
            $resource = new $class;
            if (! $resource instanceof ApiResource) {
                throw new LogicException("rest-base.resources.{$slug}: {$class} does not extend ".ApiResource::class.'.');
            }
            $descriptor = new Descriptor($slug);
            $resource->describe($descriptor);
            $this->resources[$slug] = $resource;
            $this->descriptors[$slug] = $descriptor;
        }
        foreach ($this->descriptors as $slug => $descriptor) {
            $this->check($descriptor, $this->resources[$slug]->newModel());
        }
    }

    /** @return array<string, Descriptor> */
    public function all(): array
    {
        return $this->descriptors;
    }

    public function find(string $slug): ?Descriptor
    {
        return $this->descriptors[$slug] ?? null;
    }

    public function descriptor(string $slug): Descriptor
    {
        return $this->descriptors[$slug] ?? throw new LogicException("No API resource '{$slug}'.");
    }

    public function resource(string $slug): ApiResource
    {
        return $this->resources[$slug] ?? throw new LogicException("No API resource '{$slug}'.");
    }

    /** @return list<string> the column names a model never lets out: hidden, encrypted or hashed */
    public static function secretColumns(Model $model): array
    {
        $secret = $model->getHidden();
        foreach ($model->getCasts() as $column => $cast) {
            if (is_string($cast) && (str_starts_with($cast, 'encrypted') || $cast === 'hashed' || str_contains($cast, 'Encrypted'))) {
                $secret[] = $column;
            }
        }

        return array_values(array_unique($secret));
    }

    private function check(Descriptor $d, Model $model): void
    {
        $fail = fn (string $why) => throw new LogicException("API resource '{$d->slug}': {$why}");

        if (! isset($d->attributes['id'])) {
            $fail('declare the key with ->id().');
        }
        $secret = self::secretColumns($model);
        foreach ($d->attributes as $name => $attribute) {
            if ($attribute->value === null && in_array($name, $secret, true)) {
                $fail("'{$name}' is hidden, encrypted or hashed on ".$model::class.' and can never be exposed.');
            }
            if ($attribute->isWritable() && ! $model->isFillable($name)) {
                $fail("'{$name}' is writable here but not fillable on ".$model::class.'.');
            }
        }
        foreach (array_keys($d->filters) as $name) {
            if (($d->attributes[$name] ?? null)?->value !== null || ! isset($d->attributes[$name])) {
                $fail("filter '{$name}' is not a declared (stored) attribute.");
            }
        }
        foreach ($d->sorts as $name) {
            if (($d->attributes[$name] ?? null)?->value !== null || ! isset($d->attributes[$name])) {
                $fail("sort '{$name}' is not a declared (stored) attribute.");
            }
        }
        if ($d->defaultSort !== null && ! in_array(ltrim($d->defaultSort, '-'), $d->sorts, true)) {
            $fail("the default sort '{$d->defaultSort}' is not sortable.");
        }
        foreach ($d->relationships as $name => $relation) {
            if (isset($d->attributes[$name])) {
                $fail("relationship '{$name}' has the name of an attribute.");
            }
            $target = $this->descriptors[$relation->resource] ?? $fail("relationship '{$name}' points at '{$relation->resource}', which is not registered.");
            if (! method_exists($model, $relation->method())) {
                $fail("relationship '{$name}': ".$model::class." has no method {$relation->method()}().");
            }
            if ($relation->isToOne() && ! isset($d->attributes[$relation->foreignKey])) {
                $fail("relationship '{$name}': declare its foreign key '{$relation->foreignKey}' as an attribute.");
            }
            if (! $relation->isToOne() && $relation->foreignKey !== null && ! array_key_exists($relation->foreignKey, $target->filters)) {
                $fail("relationship '{$name}': '{$relation->resource}' must make '{$relation->foreignKey}' filterable for the link.");
            }
        }
        foreach ($d->dependents as $method) {
            if (! method_exists($model, $method)) {
                $fail('dependent '.$method.'(): '.$model::class.' has no such relation.');
            }
        }
        foreach ($d->operations as $operation) {
            if (! in_array(strtoupper($operation->method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $fail("operation '{$operation->name}': unknown method {$operation->method}.");
            }
        }
    }
}
