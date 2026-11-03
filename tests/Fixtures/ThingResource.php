<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Ignited\RestBase\ApiResource;
use Ignited\RestBase\Descriptor;
use Ignited\RestBase\Operation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ThingResource extends ApiResource
{
    public function model(): string
    {
        return Thing::class;
    }

    public function describe(Descriptor $d): void
    {
        $d->endpoints('index', 'show', 'create', 'update', 'destroy')->roles(['admin', 'agent'])
            ->id()
            ->integer('owner_id', writable: true, nullable: true, exists: 'owners')
            ->string('name', writable: true, required: true)
            ->string('code', writable: 'on_create', nullable: true)
            ->text('notes', writable: true, nullable: true, showOnly: true)
            ->enum('status', Status::class, writable: true)
            ->boolean('is_active', writable: true)
            ->date('due_on', writable: true, nullable: true)
            ->json('meta', writable: true, nullable: true)
            ->number('weight', writable: true, nullable: true)
            ->computed('shout', 'string', fn (Thing $t) => strtoupper($t->name))
            ->timestamps()
            ->filterable(['owner_id', 'status', 'is_active', 'code'])
            ->filterable('created_at', range: true)
            ->filterable('weight', range: true)
            ->sortable(['name', 'created_at', 'weight'], default: '-created_at')
            ->belongsTo('owner', 'owners', 'owner_id')
            ->hasMany('parts', 'parts', 'thing_id')
            ->unique(['name'])
            ->operation(new Operation('touch', 'POST', '{id}/touch', [TouchThing::class, 'handle'], 'Touch a thing', request: null, responses: [200 => ['description' => 'Touched.']]));
    }

    public function query(Request $request): Builder
    {
        // a row rule: archived things are not on the API at all
        return Thing::query()->where('name', '!=', 'hidden-row');
    }
}
