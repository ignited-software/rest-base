<?php

namespace Ignited\RestBase;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * One model on the API. Subclass it, name the model, and describe what is exposed; every hook below has a default
 * that is enough for a plain model, and any of them can be overridden where a model needs more.
 */
abstract class ApiResource
{
    /** @return class-string<Model> */
    abstract public function model(): string;

    abstract public function describe(Descriptor $resource): void;

    /** The controller that serves this resource: the generic one, or a subclass of it. */
    public function controller(): string
    {
        return Http\ResourceController::class;
    }

    /** The rows a caller may see at all; every list and every find starts here. @return Builder<Model> */
    public function query(Request $request): Builder
    {
        return $this->model()::query();
    }

    public function newModel(): Model
    {
        $class = $this->model();

        return new $class;
    }

    /**
     * Refuses an action on a record (or on the resource, when there is no record yet) by throwing; the default
     * allows all. A place for policies or row rules beyond query().
     */
    public function authorize(string $action, Request $request, ?Model $model): void {}

    /**
     * Puts validated attributes on the model before it is saved. The default goes through `fill`, so the model's
     * own `$fillable` still holds.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fill(Model $model, array $attributes, Request $request, string $action): void
    {
        $model->fill($attributes);
    }

    /**
     * The OpenAPI operation the generator wrote for an action, to add to or change (an extra request shape, a note).
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function openApi(string $action, array $operation): array
    {
        return $operation;
    }
}
