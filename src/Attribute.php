<?php

namespace Ignited\RestBase;

use ArrayObject;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use UnitEnum;

/**
 * One attribute a descriptor exposes: its wire type, whether and when it may be written, and how it is checked.
 * Only attributes declared like this are ever read or written; nothing is derived from the table.
 */
final readonly class Attribute
{
    public const TYPES = ['string', 'text', 'integer', 'number', 'boolean', 'date', 'datetime', 'json', 'enum'];

    /**
     * @param  bool|'on_create'  $writable  true: create and update; 'on_create': only when creating; false: read-only
     * @param  list<string>  $enum  the allowed values, for type enum
     * @param  string|null  $exists  the table a written value must exist in (its key column), for a foreign key
     * @param  list<mixed>  $rules  extra validation rules, appended to the ones the type gives
     * @param  (Closure(Model): mixed)|null  $value  a computed value; such an attribute is never writable
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool|string $writable = false,
        public bool $showOnly = false,
        public bool $nullable = false,
        public bool $required = false,
        public ?int $max = null,
        public array $enum = [],
        public ?string $exists = null,
        public ?string $description = null,
        public array $rules = [],
        public ?Closure $value = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Attribute {$name}: unknown type '{$type}'.");
        }
        if (! in_array($writable, [true, false, 'on_create'], true)) {
            throw new \InvalidArgumentException("Attribute {$name}: writable is true, false or 'on_create'.");
        }
        if ($value !== null && $writable !== false) {
            throw new \InvalidArgumentException("Attribute {$name}: a computed attribute cannot be writable.");
        }
    }

    public function writableOn(string $action): bool
    {
        return $this->writable === true || ($this->writable === 'on_create' && $action === 'create');
    }

    public function isWritable(): bool
    {
        return $this->writable !== false;
    }

    /** The value as it goes on the wire: enums as their value, dates as ISO-8601, collections as arrays. */
    public function read(Model $model): mixed
    {
        $value = $this->value !== null ? ($this->value)($model) : $model->getAttribute($this->name);

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $this->type === 'date'
                ? $value->format('Y-m-d')
                : CarbonImmutable::instance($value)->toIso8601String(),
            $value instanceof ArrayObject => $value->getArrayCopy(),
            $value instanceof Arrayable => $value->toArray(),
            default => $value,
        };
    }

    /** @return list<mixed> */
    public function rules(string $action): array
    {
        $presence = $action === 'create' && $this->required ? ['required'] : ['sometimes'];
        if ($this->nullable) {
            $presence[] = 'nullable';
        } elseif ($this->type === 'json') {
            $presence[] = 'present';
        } elseif (! in_array('required', $presence, true)) {
            $presence[] = 'required';
        }

        $type = match ($this->type) {
            'string' => array_filter(['string', $this->max ? "max:{$this->max}" : null]),
            'text' => array_filter(['string', $this->max ? "max:{$this->max}" : null]),
            'integer' => ['integer'],
            'number' => ['numeric'],
            'boolean' => ['boolean:strict'],
            'date' => ['date_format:Y-m-d'],
            'datetime' => ['date'],
            'json' => ['array'],
            'enum' => [Rule::in($this->enum)],
        };
        if ($this->exists !== null) {
            $type[] = Rule::exists($this->exists, 'id');
        }

        return [...$presence, ...$type, ...$this->rules];
    }

    /** A validated input value as the model takes it. */
    public function fromInput(mixed $value): mixed
    {
        return $this->type === 'datetime' && is_string($value) ? CarbonImmutable::parse($value) : $value;
    }

    /** @return array<string, mixed> the OpenAPI 3.1 schema of this attribute */
    public function schema(): array
    {
        $schema = match ($this->type) {
            'string', 'text' => array_filter(['type' => 'string', 'maxLength' => $this->max]),
            'integer' => ['type' => 'integer'],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            'json' => ['type' => ['object', 'array']],
            'enum' => ['type' => 'string', 'enum' => $this->enum],
        };
        if ($this->nullable) {
            $schema['type'] = [...(array) $schema['type'], 'null'];
            if (isset($schema['enum'])) {
                $schema['enum'][] = null;
            }
        }
        if (! $this->isWritable()) {
            $schema['readOnly'] = true;
        }
        if ($this->description !== null) {
            $schema['description'] = $this->description;
        }

        return $schema;
    }
}
