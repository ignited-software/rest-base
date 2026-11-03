<?php

use Ignited\RestBase\ApiResource;
use Ignited\RestBase\Descriptor;
use Ignited\RestBase\Registry;
use Ignited\RestBase\Tests\Fixtures\OwnerResource;
use Ignited\RestBase\Tests\Fixtures\Thing;

function thingWith(Closure $describe): string
{
    $class = new class extends ApiResource
    {
        public static Closure $describe;

        public function model(): string
        {
            return Thing::class;
        }

        public function describe(Descriptor $d): void
        {
            (self::$describe)($d);
        }
    };
    $class::$describe = $describe;

    return $class::class;
}

it('refuses a descriptor that would expose a hidden, encrypted or hashed column', function () {
    new Registry(['things' => thingWith(fn (Descriptor $d) => $d->id()->string('api_key'))]);
})->throws(LogicException::class, "'api_key' is hidden, encrypted or hashed");

it('refuses a writable attribute the model does not fill', function () {
    new Registry(['things' => thingWith(fn (Descriptor $d) => $d->id()->string('id_card', writable: true))]);
})->throws(LogicException::class, 'not fillable');

it('refuses filters, sorts and relationships on names it does not know', function (Closure $describe, string $message) {
    // things first: its own mistake is the first one found, and the message says which
    expect(fn () => new Registry(['things' => thingWith($describe), 'owners' => OwnerResource::class]))
        ->toThrow(LogicException::class, $message);
})->with([
    [fn (Descriptor $d) => $d->id()->filterable('notes'), "filter 'notes'"],
    [fn (Descriptor $d) => $d->id()->sortable(['notes']), "sort 'notes'"],
    [fn (Descriptor $d) => $d->id()->integer('owner_id')->belongsTo('owner', 'ghosts', 'owner_id'), "'ghosts', which is not registered"],
    [fn (Descriptor $d) => $d->id()->hasMany('parts', 'owners', 'thing_id'), "'owners' must make 'thing_id' filterable"],
    [fn (Descriptor $d) => $d->string('name'), 'declare the key'],
    [fn (Descriptor $d) => $d->id()->unique(['api_key']), "unique column 'api_key'"],
]);

it('refuses an attribute a model with a $visible list leaves out', function () {
    $model = new class extends Thing
    {
        protected $table = 'things';

        protected $visible = ['id', 'name'];
    };
    $resource = new class extends ApiResource
    {
        public static string $model;

        public function model(): string
        {
            return self::$model;
        }

        public function describe(Descriptor $d): void
        {
            $d->id()->string('name')->string('code');
        }
    };
    $resource::$model = $model::class;

    new Registry(['things' => $resource::class]);
})->throws(LogicException::class, "'code' is not in the \$visible list");

it('accepts the fixtures', function () {
    expect(array_keys(app(Registry::class)->all()))->toBe(['owners', 'things', 'parts'])
        ->and(app(Registry::class)->descriptor('things')->rolesFor('touch'))->toBe(['admin', 'agent']);
});

it('keeps routes to what is registered and enabled', function () {
    expect(collect(app('router')->getRoutes())->map->uri()->filter(fn ($u) => str_starts_with($u, 'api/v2'))->values()->all())
        ->toContain('api/v2/things', 'api/v2/things/{id}', 'api/v2/things/{id}/touch', 'api/v2/openapi.json');
});
