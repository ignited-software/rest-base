<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Illuminate\Http\Request;

class TouchThing
{
    public function handle(Request $request, string $id)
    {
        return response()->json(['data' => ['touched' => (int) $id]]);
    }
}
