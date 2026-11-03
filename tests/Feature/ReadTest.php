<?php

use Ignited\RestBase\Tests\Fixtures\Owner;
use Ignited\RestBase\Tests\Fixtures\Part;
use Ignited\RestBase\Tests\Fixtures\Thing;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->owner = Owner::query()->create(['name' => 'Ann']);
    $this->thing = Thing::query()->create(['owner_id' => $this->owner->id, 'name' => 'lamp', 'notes' => 'bright', 'status' => 'open', 'weight' => 2.5, 'api_key' => 'sk_live_secret_value', 'meta' => ['a' => 1]]);
    Thing::query()->create(['name' => 'desk', 'status' => 'done', 'is_active' => false, 'weight' => 30]);
    Part::query()->create(['thing_id' => $this->thing->id, 'label' => 'bulb']);
});

it('lists the summary: allowlisted attributes, foreign keys and links, no show-only fields or secrets', function () {
    $res = $this->api('GET', '/api/v2/things?sort=name')->assertOk();

    $res->assertJsonPath('meta', ['page' => 1, 'per_page' => 100, 'total' => 2, 'total_pages' => 1])
        ->assertJsonPath('data.0.name', 'desk')
        ->assertJsonPath('data.1.owner_id', $this->owner->id)
        ->assertJsonPath('data.1.shout', 'LAMP')
        ->assertJsonPath('data.1.links.self', url('api/v2/things/'.$this->thing->id))
        ->assertJsonPath('data.1.links.owner', url('api/v2/owners/'.$this->owner->id))
        ->assertJsonPath('data.1.links.parts', url('api/v2/parts').'?'.http_build_query(['filter' => ['thing_id' => $this->thing->id]]));
    expect($res->json('data.1'))->not->toHaveKeys(['notes', 'created_at', 'owner', 'api_key'])
        ->and($res->getContent())->not->toContain('sk_live');
});

it('shows a record with its belongs-to embedded and has-many on include', function () {
    $res = $this->api('GET', "/api/v2/things/{$this->thing->id}?include=parts")->assertOk();

    $res->assertJsonPath('data.notes', 'bright')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.meta', ['a' => 1])
        ->assertJsonPath('data.owner.name', 'Ann')
        ->assertJsonPath('data.parts.0.label', 'bulb');
    expect($res->json('data.owner'))->not->toHaveKey('created_at')
        ->and($res->json('data.created_at'))->toBeString()
        ->and($res->getContent())->not->toContain('sk_live');
});

it('filters with a comma list, booleans and range operators', function () {
    $this->api('GET', '/api/v2/things?filter[status]=open,done')->assertJsonPath('meta.total', 2);
    $this->api('GET', '/api/v2/things?filter[is_active]=false')->assertJsonPath('data.0.name', 'desk');
    $this->api('GET', '/api/v2/things?filter[weight][gte]=10')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'desk');
    $this->api('GET', '/api/v2/things?filter[weight][lt]=10&filter[owner_id]='.$this->owner->id)->assertJsonPath('data.0.name', 'lamp');
    $this->api('GET', '/api/v2/things?filter[created_at][gte]=2000-01-01T00:00:00Z')->assertJsonPath('meta.total', 2);
});

it('refuses unknown or malformed query parameters with a 422 and a reason', function (string $query, string $reason) {
    $this->api('GET', "/api/v2/things?{$query}")->assertStatus(422)
        ->assertJsonPath('error.status', 422)
        ->assertJsonPath('error.code', 'unprocessable_entity')
        ->assertJsonPath('error.reason', $reason);
})->with([
    ['filter[notes]=x', 'unknown_filter'],
    ['filter[status][gte]=open', 'invalid_filter_operator'],
    ['filter[weight][like]=1', 'invalid_filter_operator'],
    ['filter[owner_id]=abc', 'invalid_filter_value'],
    ['filter[status]=maybe', 'invalid_filter_value'],
    ['sort=notes', 'unknown_sort'],
    ['fields[things]=nope', 'unknown_field'],
    ['fields[ghosts]=id', 'unknown_field'],
    ['include=parts', 'include_not_supported_on_index'],
    ['page=0', 'invalid_page'],
    ['page=99999999999999999999', 'invalid_page'],
    ['per_page=abc', 'invalid_page'],
    ['color=red', 'unknown_parameter'],
]);

it('refuses an unknown include on show', function () {
    $this->api('GET', "/api/v2/things/{$this->thing->id}?include=ghosts")->assertStatus(422)->assertJsonPath('error.reason', 'unknown_include');
});

it('sorts, with a default and a descending prefix', function () {
    $this->api('GET', '/api/v2/things?sort=-weight')->assertJsonPath('data.0.name', 'desk');
    $this->api('GET', '/api/v2/things?sort=weight')->assertJsonPath('data.0.name', 'lamp');
    $this->api('GET', '/api/v2/owners')->assertOk()->assertJsonPath('data.0.name', 'Ann');
});

it('trims to sparse fieldsets, keeping id, also on embeds', function () {
    $this->api('GET', '/api/v2/things?fields[things]=name&sort=name')->assertJsonPath('data.0', ['id' => Thing::query()->where('name', 'desk')->value('id'), 'name' => 'desk']);

    $res = $this->api('GET', "/api/v2/things/{$this->thing->id}?fields[things]=name,owner&fields[owners]=name");
    expect($res->json('data'))->toBe(['id' => $this->thing->id, 'name' => 'lamp', 'owner' => ['id' => $this->owner->id, 'name' => 'Ann']]);
});

it('pages, clamps per_page to the maximum and links every page', function () {
    foreach (range(1, 3) as $i) {
        Thing::query()->create(['name' => "extra-{$i}"]);
    }
    $res = $this->api('GET', '/api/v2/things?per_page=2&page=2&sort=name')->assertOk();
    $res->assertJsonPath('meta', ['page' => 2, 'per_page' => 2, 'total' => 5, 'total_pages' => 3])
        ->assertJsonCount(2, 'data');
    expect($res->json('links.next'))->toContain('page=3')->toContain('sort=name')
        ->and($res->json('links.prev'))->toContain('page=1')
        ->and($res->json('links.last'))->toContain('page=3');

    $this->api('GET', '/api/v2/things?per_page=5000')->assertJsonPath('meta.per_page', 1000);
    $this->api('GET', '/api/v2/things?page=9')->assertOk()->assertJsonPath('data', [])->assertJsonPath('links.next', null);
});

it('answers 404 in the error shape for a missing record, a row the resource hides, and an unknown path', function () {
    $this->api('GET', '/api/v2/things/999')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $hidden = Thing::query()->create(['name' => 'hidden-row']);
    $this->api('GET', "/api/v2/things/{$hidden->id}")->assertNotFound();
    $this->api('GET', '/api/v2/ghosts')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('lets only callers with one of the declared roles in', function () {
    $this->api('GET', '/api/v2/things', roles: '')->assertForbidden()->assertJsonPath('error.reason', 'role_not_allowed');
    $this->api('GET', '/api/v2/things', roles: 'visitor')->assertForbidden();
    $this->api('GET', '/api/v2/things', roles: 'admin')->assertOk();
});

it('runs an operation through the same role check', function () {
    $this->api('POST', "/api/v2/things/{$this->thing->id}/touch")->assertOk()->assertJsonPath('data.touched', $this->thing->id);
    $this->api('POST', "/api/v2/things/{$this->thing->id}/touch", roles: 'visitor')->assertForbidden();
});

it('runs authorize on a list, and on every embedded record, so an embed never shows a refused row', function () {
    $this->withServerVariables(['HTTP_X_REFUSE' => 'index'])->api('GET', '/api/v2/owners')->assertForbidden();

    $private = Owner::query()->create(['name' => 'Private']);
    $mine = Thing::query()->create(['name' => 'chair', 'owner_id' => $private->id]);
    $this->api('GET', "/api/v2/owners/{$private->id}")->assertForbidden();
    $this->api('GET', "/api/v2/things/{$mine->id}")->assertOk()->assertJsonPath('data.owner', null);

    Thing::query()->create(['name' => 'sealed', 'owner_id' => $this->owner->id]);
    $this->api('GET', "/api/v2/owners/{$this->owner->id}?include=things")->assertOk()
        ->assertJsonCount(1, 'data.things')->assertJsonPath('data.things.0.name', 'lamp');
});

it('lets an HttpResponseException send its own response, and hides the model class of a findOrFail', function () {
    Route::get('api/v2/teapot', fn () => throw new HttpResponseException(response()->json(['brewing' => true], 418)));
    Route::get('api/v2/lookup', fn () => Thing::query()->findOrFail(999));

    $this->api('GET', '/api/v2/teapot')->assertStatus(418)->assertExactJson(['brewing' => true]);
    $res = $this->api('GET', '/api/v2/lookup')->assertNotFound()->assertJsonPath('error.message', 'No such record.');
    expect($res->getContent())->not->toContain('Fixtures');
});
