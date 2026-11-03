<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Illuminate\Http\Request;

class HeaderRoles
{
    /** @return list<string> */
    public function __invoke(Request $request): array
    {
        return array_filter(explode(',', (string) $request->header('X-Roles')));
    }
}
