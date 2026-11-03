<?php

use Ignited\RestBase\OpenApi;

it('builds an OpenAPI 3.1 document from the descriptors', function () {
    $spec = app(OpenApi::class)->build();

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['servers'][0]['url'])->toBe('/api/v2')
        ->and(array_keys($spec['paths']))->toContain('/owners', '/owners/{id}', '/things', '/things/{id}', '/things/{id}/touch', '/parts')
        ->and(array_keys($spec['paths']['/things']))->toBe(['get', 'post'])
        ->and(array_keys($spec['paths']['/things/{id}']))->toBe(['get', 'patch', 'delete'])
        ->and($spec['components']['schemas'])->toHaveKeys(['Thing', 'ThingSummary', 'ThingCreate', 'ThingUpdate', 'Error', 'PageMeta', 'PageLinks']);

    $thing = $spec['components']['schemas']['Thing']['properties'];
    expect($thing)->toHaveKeys(['id', 'notes', 'owner', 'parts', 'links'])->not->toHaveKey('api_key')
        ->and($thing['status']['enum'])->toBe(['open', 'done'])
        ->and($spec['components']['schemas']['ThingSummary']['properties'])->not->toHaveKeys(['notes', 'owner'])
        ->and($spec['components']['schemas']['ThingCreate']['properties'])->toHaveKey('code')->not->toHaveKeys(['id', 'shout'])
        ->and($spec['components']['schemas']['ThingUpdate']['properties'])->not->toHaveKey('code')
        ->and($spec['components']['schemas']['ThingCreate']['required'])->toBe(['name'])
        ->and($spec['components']['schemas']['ThingCreate']['additionalProperties'])->toBeFalse();

    $names = array_column($spec['paths']['/things']['get']['parameters'], 'name');
    expect($names)->toContain('filter[status]', 'filter[weight][gte]', 'sort', 'fields[things]', 'per_page')
        ->not->toContain('filter[status][gte]');
});

it('says per operation who may call it', function () {
    $spec = app(OpenApi::class)->build();

    expect($spec['paths']['/owners/{id}']['delete']['x-roles'])->toBe(['admin'])
        ->and($spec['paths']['/owners/{id}']['delete']['description'])->toContain('Callable by: admin.')
        ->and($spec['paths']['/owners']['get']['x-roles'])->toBe(['admin', 'agent'])
        ->and($spec['paths']['/owners']['get']['security'])->toBe([['bearer' => []]])
        ->and($spec['paths']['/parts']['get'])->not->toHaveKey('x-roles');
});

it('serves the document, and the committed file when there is one', function () {
    $this->api('GET', '/api/v2/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');

    $file = sys_get_temp_dir().'/rest-base-'.uniqid().'.json';
    $this->artisan('rest-base:openapi', ['--output' => $file])->assertSuccessful();
    expect(file_get_contents($file))->toBe(app(OpenApi::class)->json());
    $this->artisan('rest-base:openapi', ['--output' => $file, '--check' => true])->assertSuccessful();

    file_put_contents($file, '{"openapi":"committed"}');
    $this->artisan('rest-base:openapi', ['--output' => $file, '--check' => true])->assertFailed();
    config()->set('rest-base.openapi.file', $file);
    $this->api('GET', '/api/v2/openapi.json')->assertOk()->assertJsonPath('openapi', 'committed');
    unlink($file);
});

it('renders the Swagger UI page with a pinned, integrity-checked swagger-ui', function () {
    $this->get('/api-docs')->assertOk()
        ->assertSee('https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.29.1/swagger-ui-bundle.min.js', false)
        ->assertSee('integrity="sha512-', false)
        ->assertSee(json_encode(url('api/v2/openapi.json')), false);
});
