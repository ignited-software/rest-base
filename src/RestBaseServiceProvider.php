<?php

namespace Ignited\RestBase;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;

class RestBaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rest-base.php', 'rest-base');
        $this->app->singleton(Registry::class, fn () => new Registry((array) config('rest-base.resources', [])));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rest-base');
        $this->publishes([__DIR__.'/../config/rest-base.php' => config_path('rest-base.php')], 'rest-base-config');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\WriteOpenApi::class]);
        }

        // Errors under the API's prefix, the app's own (auth, throttle, 404) included, in the API's one shape.
        $handler = $this->app->make(ExceptionHandler::class);
        if (method_exists($handler, 'renderable')) {
            $handler->renderable(fn (\Throwable $e, $request) => ErrorRenderer::render($e, $request));
        }

        // The routes, unless the app draws them itself (rest-base.routes false: call Routes::register() where it wants).
        if (config('rest-base.routes', true)) {
            $this->app->booted(fn () => $this->app->routesAreCached() || Routes::register());
        }
    }
}
