<?php

namespace Ignited\RestBase\Tests;

use Ignited\RestBase\RestBaseServiceProvider;
use Ignited\RestBase\Tests\Fixtures\HeaderRoles;
use Ignited\RestBase\Tests\Fixtures\OwnerResource;
use Ignited\RestBase\Tests\Fixtures\PartResource;
use Ignited\RestBase\Tests\Fixtures\ThingResource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RestBaseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('rest-base.enabled', true);
        $app['config']->set('rest-base.roles', HeaderRoles::class);
        $app['config']->set('rest-base.resources', [
            'owners' => OwnerResource::class,
            'things' => ThingResource::class,
            'parts' => PartResource::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('owners', function (Blueprint $t) {
            $t->id();
            $t->string('name', 40);
            $t->timestamps();
        });
        Schema::create('things', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->nullable()->constrained();
            $t->string('name')->unique();
            $t->string('code')->nullable();
            $t->text('notes')->nullable();
            $t->string('status')->default('open');
            $t->boolean('is_active')->default(true);
            $t->date('due_on')->nullable();
            $t->json('meta')->nullable();
            $t->float('weight')->nullable();
            $t->text('api_key')->nullable();
            $t->timestamps();
        });
        Schema::create('parts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thing_id')->constrained();
            $t->string('label');
            $t->timestamps();
        });
    }

    /** A JSON call as a caller with these roles. */
    protected function api(string $method, string $uri, mixed $data = null, string $roles = 'agent'): TestResponse
    {
        $body = $data === null ? '' : (is_string($data) ? $data : json_encode($data));

        return $this->call($method, $uri, [], [], [], ['HTTP_X_ROLES' => $roles, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }
}
