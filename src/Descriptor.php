<?php

namespace Ignited\RestBase;

use Closure;
use InvalidArgumentException;

/**
 * What one resource exposes: its endpoints, the allowlisted attributes and when each may be written, filters,
 * sorts, relationships, who may call what, and what stops a delete. Built fluently in ApiResource::describe().
 */
final class Descriptor
{
    public const ACTIONS = ['index', 'show', 'create', 'update', 'destroy'];

    public const RANGE_OPERATORS = ['gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<'];

    /** @var list<string> */
    public array $endpoints = ['index', 'show'];

    /** @var array<string, Attribute> */
    public array $attributes = [];

    /** @var array<string, bool> name => range operators allowed */
    public array $filters = [];

    /** @var list<string> */
    public array $sorts = [];

    public ?string $defaultSort = null;

    /** @var array<string, Relationship> */
    public array $relationships = [];

    /** @var array<string, list<string>>|null action => roles; null until roles() is called: no role check */
    public ?array $roles = null;

    /** @var list<list<string>> column sets that must be unique; a create that repeats one is a 422 `duplicate` */
    public array $unique = [];

    /** @var list<string> relation methods that must be empty before a delete */
    public array $dependents = [];

    /** @var array<string, Operation> */
    public array $operations = [];

    public ?string $label = null;

    public ?string $description = null;

    public ?int $perPage = null;

    public ?int $maxPerPage = null;

    public string $keyType = 'integer';

    public function __construct(public readonly string $slug) {}

    public function label(string $label, ?string $description = null): self
    {
        $this->label = $label;
        $this->description = $description;

        return $this;
    }

    public function endpoints(string ...$actions): self
    {
        foreach ($actions as $action) {
            if (! in_array($action, self::ACTIONS, true)) {
                throw new InvalidArgumentException("{$this->slug}: unknown endpoint '{$action}'.");
            }
        }
        $this->endpoints = array_values(array_unique($actions));

        return $this;
    }

    public function has(string $action): bool
    {
        return in_array($action, $this->endpoints, true);
    }

    /**
     * Who may call the endpoints: the app's role resolver (config rest-base.roles) names the caller's roles, and a
     * caller needs one of these. Without `$actions` it applies to every endpoint and operation.
     *
     * @param  list<string>  $roles
     */
    public function roles(array $roles, string ...$actions): self
    {
        $this->roles ??= [];
        foreach ($actions ?: [...self::ACTIONS, '*'] as $action) {
            $this->roles[$action] = array_values($roles);
        }

        return $this;
    }

    /** @return list<string>|null the roles that may call an action; null = no role check */
    public function rolesFor(string $action): ?array
    {
        if ($this->roles === null) {
            return null;
        }
        if (isset($this->operations[$action])) {
            $operation = $this->operations[$action];

            return $operation->roles ?? $this->roles[$operation->isRead() ? 'index' : 'create'] ?? $this->roles['*'] ?? [];
        }

        return $this->roles[$action] ?? $this->roles['*'] ?? [];
    }

    public function attribute(Attribute $attribute): self
    {
        $this->attributes[$attribute->name] = $attribute;

        return $this;
    }

    /** The key, read-only and always present. */
    public function id(string $type = 'integer'): self
    {
        $this->keyType = $type;

        return $this->attribute(new Attribute('id', $type === 'integer' ? 'integer' : 'string'));
    }

    /** created_at and updated_at, read-only and only on show. */
    public function timestamps(bool $updated = true): self
    {
        $this->datetime('created_at', showOnly: true);

        return $updated ? $this->datetime('updated_at', showOnly: true) : $this;
    }

    public function string(string $name, bool|string $writable = false, bool $nullable = false, bool $required = false, ?int $max = null, bool $showOnly = false, ?string $description = null, array $rules = []): self
    {
        return $this->attribute(new Attribute($name, 'string', $writable, $showOnly, $nullable, $required, $max, description: $description, rules: $rules));
    }

    public function text(string $name, bool|string $writable = false, bool $nullable = false, bool $required = false, ?int $max = null, bool $showOnly = false, ?string $description = null, array $rules = []): self
    {
        return $this->attribute(new Attribute($name, 'text', $writable, $showOnly, $nullable, $required, $max, description: $description, rules: $rules));
    }

    /** @param  string|null  $exists  the table a written value must name a row of (a foreign key) */
    public function integer(string $name, bool|string $writable = false, bool $nullable = false, bool $required = false, ?string $exists = null, bool $showOnly = false, ?string $description = null, array $rules = []): self
    {
        return $this->attribute(new Attribute($name, 'integer', $writable, $showOnly, $nullable, $required, exists: $exists, description: $description, rules: $rules));
    }

    public function number(string $name, bool|string $writable = false, bool $nullable = false, bool $required = false, bool $showOnly = false, ?string $description = null): self
    {
        return $this->attribute(new Attribute($name, 'number', $writable, $showOnly, $nullable, $required, description: $description));
    }

    public function boolean(string $name, bool|string $writable = false, bool $nullable = false, bool $required = false, bool $showOnly = false, ?string $description = null): self
    {
        return $this->attribute(new Attribute($name, 'boolean', $writable, $showOnly, $nullable, $required, description: $description));
    }

    public function date(string $name, bool|string $writable = false, bool $nullable = false, bool $required = false, bool $showOnly = false, ?string $description = null): self
    {
        return $this->attribute(new Attribute($name, 'date', $writable, $showOnly, $nullable, $required, description: $description));
    }

    public function datetime(string $name, bool|string $writable = false, bool $nullable = false, bool $required = false, bool $showOnly = false, ?string $description = null): self
    {
        return $this->attribute(new Attribute($name, 'datetime', $writable, $showOnly, $nullable, $required, description: $description));
    }

    public function json(string $name, bool|string $writable = false, bool $nullable = false, bool $required = false, bool $showOnly = false, ?string $description = null): self
    {
        return $this->attribute(new Attribute($name, 'json', $writable, $showOnly, $nullable, $required, description: $description));
    }

    /** @param  list<string>|class-string<\BackedEnum>  $values  the allowed values, or a backed enum's class */
    public function enum(string $name, array|string $values, bool|string $writable = false, bool $nullable = false, bool $required = false, bool $showOnly = false, ?string $description = null): self
    {
        $values = is_string($values) ? array_map(fn (\BackedEnum $case) => (string) $case->value, $values::cases()) : array_values($values);

        return $this->attribute(new Attribute($name, 'enum', $writable, $showOnly, $nullable, $required, enum: $values, description: $description));
    }

    /** A read-only value worked out per record (never a column the API writes). */
    public function computed(string $name, string $type, Closure $value, bool $nullable = false, bool $showOnly = false, ?string $description = null): self
    {
        return $this->attribute(new Attribute($name, $type, false, $showOnly, $nullable, description: $description, value: $value));
    }

    /** ?filter[name]=a,b (comma = any of); with `range`, also ?filter[name][gte|gt|lte|lt]=value. */
    public function filterable(string|array $names, bool $range = false): self
    {
        foreach ((array) $names as $name) {
            $this->filters[$name] = $range;
        }

        return $this;
    }

    /** ?sort=name,-created_at; `$default` is used without a sort (prefix '-' for descending). */
    public function sortable(array $names, ?string $default = null): self
    {
        $this->sorts = array_values(array_unique([...$this->sorts, ...$names]));
        $this->defaultSort = $default ?? $this->defaultSort;

        return $this;
    }

    public function belongsTo(string $name, string $resource, string $foreignKey, ?string $method = null): self
    {
        $this->relationships[$name] = new Relationship($name, Relationship::BELONGS_TO, $resource, $foreignKey, $method);

        return $this;
    }

    /** @param  string|null  $foreignKey  the column on the target that points here; the target must make it filterable */
    public function hasMany(string $name, string $resource, ?string $foreignKey, ?string $method = null): self
    {
        $this->relationships[$name] = new Relationship($name, Relationship::HAS_MANY, $resource, $foreignKey, $method);

        return $this;
    }

    /** @param  list<string>  $columns */
    public function unique(array $columns): self
    {
        $this->unique[] = $columns;

        return $this;
    }

    /** Relation methods that must be empty before a delete: otherwise a 422 `has_dependent_records`, never a cascade. */
    public function dependents(string ...$relations): self
    {
        $this->dependents = array_values(array_unique([...$this->dependents, ...$relations]));

        return $this;
    }

    public function operation(Operation $operation): self
    {
        $this->operations[$operation->name] = $operation;

        return $this;
    }

    public function perPage(int $default, ?int $max = null): self
    {
        $this->perPage = $default;
        $this->maxPerPage = $max;

        return $this;
    }

    /** @return list<Attribute> what a representation shows: index leaves out the show-only ones */
    public function visible(bool $detailed): array
    {
        return array_values(array_filter($this->attributes, fn (Attribute $a) => $detailed || ! $a->showOnly));
    }

    /** @return list<Attribute> */
    public function writable(string $action): array
    {
        return array_values(array_filter($this->attributes, fn (Attribute $a) => $a->writableOn($action)));
    }
}
