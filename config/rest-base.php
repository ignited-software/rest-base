<?php

/*
 * ignited/rest-base: a JSON REST API built from one descriptor per model. Nothing is exposed until a resource
 * is listed here; the app decides the prefix and the middleware (its own auth).
 */
return [
    'enabled' => false,

    // slug => descriptor class (extends Ignited\RestBase\ApiResource)
    'resources' => [],
];
