<?php

namespace Ignited\RestBase;

/**
 * An endpoint of a resource beyond the five CRUD ones (for example POST /repos/sync). It gets the same prefix,
 * middleware, role check and error format, and appears in the OpenAPI document; the handler is the app's own.
 */
final readonly class Operation
{
    /**
     * @param  string  $method  GET, POST, PUT, PATCH or DELETE
     * @param  string  $path  relative to the resource's collection: 'sync', or '{id}/archive' for a member
     * @param  array{0: class-string, 1: string}|class-string  $handler  a controller action or an invokable class
     * @param  list<string>|null  $roles  who may call it; null = the resource's roles for its writes (GET: its reads)
     * @param  array<string, mixed>|null  $request  the OpenAPI schema of the request body, if any
     * @param  array<int, array<string, mixed>>  $responses  status => OpenAPI schema of the response body
     */
    public function __construct(
        public string $name,
        public string $method,
        public string $path,
        public array|string $handler,
        public string $summary,
        public ?string $description = null,
        public ?array $roles = null,
        public ?array $request = null,
        public array $responses = [],
    ) {}

    public function isRead(): bool
    {
        return strtoupper($this->method) === 'GET';
    }
}
