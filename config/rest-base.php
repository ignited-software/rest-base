<?php

/*
 * ignited/rest-base: a JSON REST API built from one descriptor per model. Nothing is exposed until a resource
 * is listed here; the app decides the prefix and supplies the auth through the middleware.
 */
return [
    // Off until the app turns it on: no routes, no error rendering.
    'enabled' => false,

    // slug => descriptor class (extends Ignited\RestBase\ApiResource); the slug is the URL segment.
    'resources' => [],

    'prefix' => 'api/v2',

    // The app's own stack in front of every endpoint: its auth, its throttle.
    'middleware' => ['api'],

    // Draw the routes when the app boots; false: the app calls Ignited\RestBase\Routes::register() itself.
    'routes' => true,

    'route_name' => 'rest-base.',

    // An invokable class: (Request) => list<string>, the caller's roles. Descriptors that declare roles admit
    // only callers holding one; null with roles declared admits nobody.
    'roles' => null,

    // Exceptions a model's saving or deleting hook throws to refuse a write: answered as 422 `refused` with
    // their message (so write those messages for the caller). Anything else is a 500.
    'refusals' => [LogicException::class, InvalidArgumentException::class],

    'pagination' => [
        'per_page' => 100,
        'max_per_page' => 1000,
    ],

    'openapi' => [
        'enabled' => true,
        // under the prefix: /api/v2/openapi.json
        'path' => 'openapi.json',
        // null: the API's middleware
        'middleware' => null,
        // The committed document (php artisan rest-base:openapi writes it); served as is when it exists.
        'file' => null,
        'title' => 'API',
        'version' => '1.0.0',
        'description' => null,
        // components.securitySchemes; every operation requires one of them.
        'security_schemes' => [
            'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
        ],
    ],

    // The Swagger UI page.
    'docs' => [
        'enabled' => true,
        'path' => 'api-docs',
        'middleware' => ['web'],
        'title' => null,
        // swagger-ui-dist from cdnjs, an exact version with its subresource integrity hashes
        'swagger_ui' => [
            'base' => 'https://cdnjs.cloudflare.com/ajax/libs/swagger-ui',
            'version' => '5.29.1',
            'css_integrity' => 'sha512-QIJpSy6rqOKoEjIR+Pp7r5lTkNPJPJCRejWzG4jb12bCT1EeL/vcjZtk4NNKeEuVRXY+d34d/t9y1CHzZbgeJQ==',
            'js_integrity' => 'sha512-yfAKqi7F3JSDZA7V/XTql3caj2o8A81yg2EWIBykt2BJjLjNYnsd4CVAKvrFp/nYl9HQX7R06Z6e5KBn5aoj6g==',
        ],
    ],
];
