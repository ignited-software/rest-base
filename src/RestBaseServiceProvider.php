<?php

namespace Ignited\RestBase;

use Illuminate\Support\ServiceProvider;

class RestBaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rest-base.php', 'rest-base');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/rest-base.php' => config_path('rest-base.php')], 'rest-base-config');
    }
}
