<?php

namespace Ignited\RestBase;

/**
 * A relation of a resource to another registered resource. A belongs-to is embedded on show (through the target's
 * own descriptor, so its allowlist holds) and linked by its foreign key; a has-many is linked as the target's list
 * filtered by the foreign key, and embedded on show only when asked for with ?include.
 */
final readonly class Relationship
{
    public const BELONGS_TO = 'belongs_to';

    public const HAS_MANY = 'has_many';

    /**
     * @param  string  $resource  the target resource's slug
     * @param  string|null  $foreignKey  belongs-to: the column on this model; has-many: the column on the target (null: no link)
     * @param  string|null  $method  the Eloquent relation method; default: the name
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $resource,
        public ?string $foreignKey,
        public ?string $method = null,
    ) {}

    public function method(): string
    {
        return $this->method ?? $this->name;
    }

    public function isToOne(): bool
    {
        return $this->kind === self::BELONGS_TO;
    }
}
