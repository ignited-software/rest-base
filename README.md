# ignited/rest-base

A descriptor-driven JSON REST API for Eloquent models. One descriptor per model says which attributes are read
and written, what can be filtered and sorted, how records relate and who may call what. The routes, the request
pipeline (filters, sort, sparse fieldsets, pagination, one envelope, one error shape) and an OpenAPI 3.1 document
with a Swagger UI page are built from those descriptors. **Nothing is exposed that a descriptor does not name.**

The app supplies authentication (route middleware) and, if it wants, roles (who the caller is). The package
knows no app.

## Install

```bash
composer require ignited/rest-base
php artisan vendor:publish --tag=rest-base-config      # config/rest-base.php
```

```php
// config/rest-base.php
'enabled' => true,
'prefix' => 'api/v2',
'middleware' => ['api', 'auth:sanctum'],          // your auth, your throttle
'roles' => App\Api\CallerRoles::class,            // optional: (Request) => list<string>
'resources' => [
    'repos' => App\Api\RepoResource::class,
],
```

## A descriptor

```php
use Ignited\RestBase\ApiResource;
use Ignited\RestBase\Descriptor;

class RepoResource extends ApiResource
{
    public function model(): string
    {
        return Repo::class;
    }

    public function describe(Descriptor $d): void
    {
        $d->label('Repos', 'GitHub repositories and the project each belongs to.')
            ->endpoints('index', 'show', 'create', 'update', 'destroy')
            ->roles(['human', 'agent'])                      // every endpoint
            ->roles(['human'], 'destroy')                    // narrower for one
            ->id()
            ->string('owner', writable: 'on_create', required: true, max: 100)
            ->string('name', writable: 'on_create', required: true, max: 100)
            ->integer('project_id', writable: true, nullable: true, exists: 'projects')
            ->string('description', writable: true, nullable: true, max: 300)
            ->timestamps()                                   // show-only created_at / updated_at
            ->filterable(['owner', 'name', 'project_id'])
            ->filterable('created_at', range: true)          // filter[created_at][gte]=...
            ->sortable(['owner', 'name', 'created_at'], default: 'owner')
            ->belongsTo('project', 'projects', 'project_id') // embedded on show
            ->hasMany('issues', 'issues', 'repo_id')         // linked; ?include=issues on show
            ->unique(['owner', 'name'])                      // 422 duplicate instead of a constraint error
            ->dependents('issues');                          // delete refused while issues exist
    }
}
```

Attribute types: `string`, `text`, `integer`, `number`, `boolean`, `date`, `datetime`, `json`, `enum` (a list or
a backed enum class), and `computed` (read-only, from a closure). Per attribute: `writable` (`true`, `'on_create'`,
`false`), `required` (on create), `nullable`, `max`, `exists` (a foreign key's table), `showOnly`, extra `rules`.

### Checked at boot

Building the registry fails (so the app and its tests fail) when a descriptor:

- names an attribute the model hides, encrypts or hashes (`$hidden`, `encrypted*`, `hashed` casts), or one a
  model with a `$visible` list leaves out: **secrets can never be declared**, so they can never be serialised,
  filtered or sorted on;
- makes an attribute writable that the model's `$fillable` does not allow;
- filters, sorts, sets a unique set or relates on a name it does not declare, or points a relationship at an
  unregistered resource;
- links a has-many whose foreign key the target does not make filterable.

### Hooks (override in the ApiResource)

| Method | Default | For |
|---|---|---|
| `query(Request)` | `Model::query()` | row rules: every list, find and embed starts here |
| `authorize($action, Request, ?Model)` | allow | policies, per-record refusals (throw); runs on every action (`index` and `create` with no record) and on every embedded record (`show`), which is left out when refused |
| `fill(Model, array, Request, $action)` | `$model->fill()` | attribution, derived columns |
| `controller()` | `ResourceController` | a subclass that changes one action |
| `openApi($action, array)` | unchanged | add to the generated operation |

Extra endpoints (`POST /repos/sync`) are `Operation`s on the descriptor: same prefix, middleware, role check and
error shape, and documented in the OpenAPI document; the handler is the app's. An operation without its own
`roles` takes the resource's `create` roles (a `GET` one its `index` roles): give a sensitive one (an approve, a
confirm) its own narrower `roles`.

`exists: 'table'` checks that the id names a row of that table, any row: it does not go through the target
resource's `query()`. Where row rules matter (one tenant may not point at another's record), add your own rule
in `rules`.

## The wire format

| | |
|---|---|
| List | `GET /api/v2/repos` → `{data: [...], meta: {page, per_page, total, total_pages}, links: {self, first, prev, next, last}}`. Rows are the summary: attributes (no show-only ones), foreign keys and `links`, never an embedded record |
| Record | `GET /api/v2/repos/{id}` → `{data: {..., project: {...}, links: {...}}}`: belongs-to records embedded (through their own descriptor), has-many with `?include=issues` |
| Filter | `?filter[owner]=a,b` (comma = any of), `?filter[created_at][gte]=2026-01-01` (`gte`, `gt`, `lte`, `lt` on range filters) |
| Sort | `?sort=owner,-created_at` (`-` = descending); the key breaks ties |
| Fields | `?fields[repos]=owner,name&fields[projects]=slug` (`id` always kept; `links` and embeds only when named) |
| Pages | `?page=2&per_page=100`; default 100, maximum 1000 (above it is lowered, not refused); `page` at most 999999999 |
| Create | `POST` `{data: {...}}` → 201, `Location`, the record |
| Update | `PATCH` (or `PUT`, the same) `{data: {...}}`: a partial merge; left-out attributes keep their value, `null` clears |
| Delete | 204; 422 `has_dependent_records` while dependents exist, never a cascade |

Every query parameter is checked: an unknown one is a 422, never ignored. Every body key is checked: an unknown
key is a 422 `unknown_attribute`, a read-only one (or a create-only one on update) `not_writable`, a relationship
`nested_write_not_supported`; nothing is dropped silently.

### Errors

```json
{"error": {"status": 422, "code": "unprocessable_entity", "reason": "unknown_filter", "message": "...", "details": {}}}
```

`code` follows the HTTP status; `reason` is the stable subcode to branch on: `unknown_parameter`, `unknown_filter`,
`invalid_filter_operator`, `invalid_filter_value`, `unknown_sort`, `unknown_field`, `unknown_include`,
`include_not_supported_on_index`, `invalid_page`, `invalid_json`, `missing_data`, `invalid_data`,
`unknown_attribute`, `not_writable`, `nested_write_not_supported`, `invalid_attributes`, `duplicate`, `refused`,
`constraint_violation`, `has_dependent_records`, `role_not_allowed`; `null` for a generic error.

**400 only** for unparseable JSON or a missing `data` wrapper. A model's own guard (a `saving` or `deleting` hook
that throws one of `rest-base.refusals`, by default `LogicException` and `InvalidArgumentException`) is a 422
`refused` with its message, not a 500. Those two classes are broad (PHP and libraries throw them too, with
messages written for developers): for a public API, set `rest-base.refusals` to an exception class of your own. The app's own 401, 403, 404 and 429 (with `Retry-After`) under the prefix
come in the same shape; a response the app throws itself (`HttpResponseException`) is sent as it is. Only an
integrity constraint (SQLSTATE 23) is a 422 `constraint_violation` or `has_dependent_records`; any other database
error is a reported 500.

## Roles

`rest-base.roles` names an invokable class that returns the caller's roles for a request (a token's channel, a
user's role). A descriptor's `roles()` admits callers holding one of them, per endpoint; a descriptor without
roles leaves access to the middleware alone. With roles declared and no resolver configured, nobody is admitted.
An embedded record is shown only when the caller may read its resource.

## OpenAPI and Swagger UI

- `GET /api/v2/openapi.json`: OpenAPI 3.1, built from the registry alone (no database), so it is deterministic.
  Every operation carries its `security` and `x-roles` (and "Callable by: ..." in its description). With
  `rest-base.openapi.file` set and the file present, that file is served as is: the committed document is the
  per-deploy cache.
- `php artisan rest-base:openapi` writes the document to that file (`--output=` for another); `--check` fails when
  it is out of date. Compare it in a test so drift fails CI:

  ```php
  it('has a committed OpenAPI document that matches the descriptors', function () {
      expect(file_get_contents(config('rest-base.openapi.file')))->toBe(app(Ignited\RestBase\OpenApi::class)->json());
  });
  ```

- `GET /api-docs` (`rest-base.docs`): Swagger UI, `swagger-ui-dist` from cdnjs at an exact version with
  subresource-integrity hashes. Give it the middleware of your own login.

## Tests

```bash
composer install && composer test && composer lint
```

## License

MIT, see [LICENSE](LICENSE).
