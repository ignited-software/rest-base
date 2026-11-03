<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** No relation methods on purpose: its has-many link and its delete guard work from the foreign key alone. */
class Owner extends Model
{
    protected $fillable = ['name'];
}
