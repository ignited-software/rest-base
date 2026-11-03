<?php

namespace Ignited\RestBase\Tests;

use Ignited\RestBase\RestBaseServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RestBaseServiceProvider::class];
    }
}
