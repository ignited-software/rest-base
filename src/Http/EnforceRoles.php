<?php

namespace Ignited\RestBase\Http;

use Closure;
use Ignited\RestBase\ApiException;
use Ignited\RestBase\Registry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** On every generated route: the caller's roles must include one the descriptor allows for this action. */
final class EnforceRoles
{
    public function __construct(private readonly Registry $registry, private readonly Roles $roles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $d = $this->registry->descriptor((string) $route->defaults['_rest_resource']);
        $action = (string) $route->defaults['_rest_action'];
        if (! $this->roles->allows($request, $d, $action)) {
            throw new ApiException(403, 'role_not_allowed', "This caller may not {$action} {$d->slug}.");
        }

        return $next($request);
    }
}
