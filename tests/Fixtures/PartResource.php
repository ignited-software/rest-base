<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Ignited\RestBase\ApiResource;
use Ignited\RestBase\Descriptor;

class PartResource extends ApiResource
{
    public function model(): string
    {
        return Part::class;
    }

    public function describe(Descriptor $d): void
    {
        $d->endpoints('index', 'show', 'create', 'update', 'destroy')
            ->id()->integer('thing_id', writable: 'on_create', required: true, exists: 'things')->string('label', writable: true, required: true)
            ->timestamps()->filterable('thing_id')->sortable(['label'])
            ->belongsTo('thing', 'things', 'thing_id');
    }
}
