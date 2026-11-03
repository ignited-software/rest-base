<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Owner extends Model
{
    protected $fillable = ['name'];

    public function things(): HasMany
    {
        return $this->hasMany(Thing::class);
    }
}
