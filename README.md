# ignited/rest-base

A descriptor-driven JSON REST API for Eloquent models: one descriptor per model declares which attributes are
read and written, what can be filtered and sorted, and how records relate. The routes, the request pipeline
(filters, sort, sparse fieldsets, pagination, a uniform envelope and errors) and an OpenAPI 3.1 document with a
Swagger UI page are built from those descriptors. Nothing is exposed that a descriptor does not name.

The app supplies authentication and authorisation through route middleware; the package knows no app.

Status: skeleton. The engine lands in the first feature PR.

## Tests

```bash
composer install && composer test && composer lint
```
