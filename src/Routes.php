<?php

namespace Ignited\RestBase;

use Ignited\RestBase\Http\EnforceRoles;
use Ignited\RestBase\Http\OpenApiController;
use Illuminate\Support\Facades\Route;

/**
 * Draws the API from the registry: per resource only the endpoints its descriptor lists, plus its operations,
 * under config rest-base.prefix with rest-base.middleware (the app's own auth), and the OpenAPI document and the
 * Swagger UI page where the config puts them.
 */
final class Routes
{
    public static function register(): void
    {
        if (! config('rest-base.enabled')) {
            return;
        }
        $registry = app(Registry::class);
        $prefix = trim((string) config('rest-base.prefix', 'api/v2'), '/');
        $name = (string) config('rest-base.route_name', 'rest-base.');

        Route::prefix($prefix)->middleware([...(array) config('rest-base.middleware', ['api']), EnforceRoles::class])->name($name)->group(function () use ($registry) {
            foreach ($registry->all() as $slug => $d) {
                $controller = $registry->resource($slug)->controller();
                $id = $d->keyType === 'integer' ? '[0-9]+' : '[A-Za-z0-9_-]+';
                $tag = fn ($route, string $action) => $route->defaults('_rest_resource', $slug)->defaults('_rest_action', $action);

                foreach ($d->operations as $operation) {
                    $tag(Route::match([strtoupper($operation->method)], "{$slug}/{$operation->path}", $operation->handler), $operation->name)
                        ->where('id', $id)->name("{$slug}.{$operation->name}");
                }
                if ($d->has('index')) {
                    $tag(Route::get($slug, [$controller, 'index']), 'index')->name("{$slug}.index");
                }
                if ($d->has('create')) {
                    $tag(Route::post($slug, [$controller, 'store']), 'create')->name("{$slug}.create");
                }
                if ($d->has('show')) {
                    $tag(Route::get("{$slug}/{id}", [$controller, 'show']), 'show')->where('id', $id)->name("{$slug}.show");
                }
                if ($d->has('update')) {
                    $tag(Route::match(['PATCH', 'PUT'], "{$slug}/{id}", [$controller, 'update']), 'update')->where('id', $id)->name("{$slug}.update");
                }
                if ($d->has('destroy')) {
                    $tag(Route::delete("{$slug}/{id}", [$controller, 'destroy']), 'destroy')->where('id', $id)->name("{$slug}.destroy");
                }
            }
        });

        if (config('rest-base.openapi.enabled', true)) {
            Route::get($prefix.'/'.ltrim((string) config('rest-base.openapi.path', 'openapi.json'), '/'), [OpenApiController::class, 'spec'])
                ->middleware((array) (config('rest-base.openapi.middleware') ?? config('rest-base.middleware', ['api'])))
                ->name($name.'openapi');
        }
        if (config('rest-base.docs.enabled', true)) {
            Route::get(trim((string) config('rest-base.docs.path', 'api-docs'), '/'), [OpenApiController::class, 'ui'])
                ->middleware((array) config('rest-base.docs.middleware', ['web']))
                ->name($name.'docs');
        }

        // drawn after the app's own routes were indexed: index these too, so route() finds them by name
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }
}
