<?php

namespace Ignited\RestBase\Http;

use Ignited\RestBase\Descriptor;
use Illuminate\Http\Request;
use LogicException;

/**
 * Who the caller is, in the descriptors' terms. The app names an invokable class in config rest-base.roles that
 * answers the caller's roles for a request (for example the channel of its token). A descriptor that declares
 * roles admits a caller holding one of them; a descriptor without roles leaves access to the route middleware.
 * Roles declared but no resolver configured: nobody is admitted.
 */
final class Roles
{
    /** @return list<string> */
    public function of(Request $request): array
    {
        $class = config('rest-base.roles');
        if ($class === null) {
            return [];
        }
        $resolver = app($class);
        if (! is_callable($resolver)) {
            throw new LogicException("rest-base.roles: {$class} is not invokable.");
        }

        return array_values(array_map('strval', (array) $resolver($request)));
    }

    public function allows(Request $request, Descriptor $d, string $action): bool
    {
        $allowed = $d->rolesFor($action);

        return $allowed === null || array_intersect($allowed, $this->of($request)) !== [];
    }
}
