<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Ignited\RestBase\ApiResource;
use Ignited\RestBase\Descriptor;

class OwnerResource extends ApiResource
{
    public function model(): string
    {
        return Owner::class;
    }

    public function describe(Descriptor $d): void
    {
        $d->label('Owners')->endpoints('index', 'show', 'create', 'update', 'destroy')
            ->roles(['admin', 'agent'])->roles(['admin'], 'destroy')
            ->id()->string('name', writable: true, required: true, max: 40)->timestamps()
            ->filterable('name')->sortable(['name', 'created_at'], default: 'name')
            ->hasMany('things', 'things', 'owner_id')
            ->dependents('things');
    }
}
