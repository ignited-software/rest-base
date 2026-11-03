<?php

use Ignited\RestBase\Tests\Fixtures\Owner;
use Ignited\RestBase\Tests\Fixtures\OwnerResource;
use Ignited\RestBase\Tests\Fixtures\Part;
use Ignited\RestBase\Tests\Fixtures\Thing;

it('creates a record: 201, Location, the show representation', function () {
    $owner = Owner::query()->create(['name' => 'Ann']);
    $res = $this->api('POST', '/api/v2/things', ['data' => ['name' => 'lamp', 'owner_id' => $owner->id, 'code' => 'L1', 'due_on' => '2026-12-01', 'meta' => ['x' => true], 'is_active' => false]]);

    $id = Thing::query()->value('id');
    $res->assertCreated()->assertHeader('Location', url("api/v2/things/{$id}"))
        ->assertJsonPath('data.name', 'lamp')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.due_on', '2026-12-01')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.owner.name', 'Ann');
});

it('validates types, required attributes, enums and foreign keys', function () {
    $res = $this->api('POST', '/api/v2/things', ['data' => ['owner_id' => 99, 'status' => 'maybe', 'is_active' => 'yes', 'due_on' => 'tomorrow']])->assertStatus(422);

    $res->assertJsonPath('error.reason', 'invalid_attributes');
    expect(array_keys($res->json('error.details')))->toEqualCanonicalizing(['name', 'owner_id', 'status', 'is_active', 'due_on']);
});

it('refuses unknown, read-only, create-only and relationship keys by name, never dropping them', function (array $data, string $reason, string $key) {
    $thing = Thing::query()->create(['name' => 'lamp', 'code' => 'L1']);

    $this->api('PATCH', "/api/v2/things/{$thing->id}", ['data' => $data])->assertStatus(422)
        ->assertJsonPath('error.reason', $reason)
        ->assertJsonStructure(['error' => ['details' => [$key]]]);
    expect($thing->fresh()->code)->toBe('L1');
})->with([
    [['colour' => 'red'], 'unknown_attribute', 'colour'],
    [['api_key' => 'x'], 'unknown_attribute', 'api_key'],
    [['created_at' => '2020-01-01'], 'not_writable', 'created_at'],
    [['shout' => 'X'], 'not_writable', 'shout'],
    [['code' => 'L2'], 'not_writable', 'code'],
    [['owner' => ['name' => 'Bob']], 'nested_write_not_supported', 'owner'],
]);

it('patches as a partial merge, and PUT means the same', function () {
    $thing = Thing::query()->create(['name' => 'lamp', 'notes' => 'bright', 'weight' => 1]);

    $this->api('PATCH', "/api/v2/things/{$thing->id}", ['data' => ['weight' => 3]])->assertOk()
        ->assertJsonPath('data.notes', 'bright')->assertJsonPath('data.weight', 3);
    $this->api('PUT', "/api/v2/things/{$thing->id}", ['data' => ['notes' => null]])->assertOk()
        ->assertJsonPath('data.notes', null)->assertJsonPath('data.name', 'lamp');
});

it('answers 400 only for unparseable JSON or a missing data wrapper', function () {
    $this->api('POST', '/api/v2/things', '{nope')->assertStatus(400)->assertJsonPath('error.reason', 'invalid_json');
    $this->api('POST', '/api/v2/things', ['name' => 'lamp'])->assertStatus(400)->assertJsonPath('error.reason', 'missing_data');
    $this->api('POST', '/api/v2/things', '')->assertStatus(400);
    $this->api('POST', '/api/v2/things', ['data' => [['name' => 'a']]])->assertStatus(422)->assertJsonPath('error.reason', 'invalid_data');
});

it('refuses a duplicate of a unique column set', function () {
    Thing::query()->create(['name' => 'lamp']);
    $other = Thing::query()->create(['name' => 'desk']);

    $this->api('POST', '/api/v2/things', ['data' => ['name' => 'lamp']])->assertStatus(422)->assertJsonPath('error.reason', 'duplicate');
    $this->api('PATCH', "/api/v2/things/{$other->id}", ['data' => ['name' => 'lamp']])->assertStatus(422)->assertJsonPath('error.reason', 'duplicate');
    $this->api('PATCH', "/api/v2/things/{$other->id}", ['data' => ['name' => 'desk']])->assertOk();
});

it('turns a model guard into a 422, not a 500', function () {
    $this->api('POST', '/api/v2/things', ['data' => ['name' => 'forbidden']])->assertStatus(422)
        ->assertJsonPath('error.reason', 'refused')->assertJsonPath('error.message', 'A thing may not be called forbidden.');

    $thing = Thing::query()->create(['name' => 'lamp']);
    $part = Part::query()->create(['thing_id' => $thing->id, 'label' => 'bulb']);
    $this->api('PATCH', "/api/v2/parts/{$part->id}", ['data' => ['label' => 'led']])->assertStatus(422)->assertJsonPath('error.message', 'Parts are append-only.');
    $this->api('DELETE', "/api/v2/parts/{$part->id}")->assertStatus(422)->assertJsonPath('error.reason', 'refused');
});

it('refuses to delete a record others depend on, and deletes a leaf with 204', function () {
    $owner = Owner::query()->create(['name' => 'Ann']);
    $thing = Thing::query()->create(['name' => 'lamp', 'owner_id' => $owner->id]);

    $this->api('DELETE', "/api/v2/owners/{$owner->id}", roles: 'admin')->assertStatus(422)
        ->assertJsonPath('error.reason', 'has_dependent_records')->assertJsonStructure(['error' => ['details' => ['things']]]);

    $this->api('DELETE', "/api/v2/things/{$thing->id}")->assertNoContent();
    $this->api('DELETE', "/api/v2/owners/{$owner->id}", roles: 'agent')->assertForbidden();
    $this->api('DELETE', "/api/v2/owners/{$owner->id}", roles: 'admin')->assertNoContent();
    expect(Owner::query()->count())->toBe(0);
});

it('has only the endpoints a descriptor lists', function () {
    config()->set('rest-base.resources.owners', OwnerResource::class);
    $this->api('GET', '/api/v2/things/1/nope')->assertNotFound();
    $this->api('PATCH', '/api/v2/things')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
});
