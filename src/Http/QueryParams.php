<?php

namespace Ignited\RestBase\Http;

use Carbon\CarbonImmutable;
use Ignited\RestBase\ApiException;
use Ignited\RestBase\Attribute;
use Ignited\RestBase\Descriptor;
use Ignited\RestBase\Registry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * The query string of a list or a record, checked against the descriptor. Every name is validated: an unknown
 * filter, sort, field, include or parameter is a 422, never ignored.
 */
final class QueryParams
{
    private const INDEX_KEYS = ['filter', 'sort', 'fields', 'page', 'per_page'];

    private const SHOW_KEYS = ['fields', 'include'];

    /** @var array<string, array{op: string, value: mixed}> */
    public array $filters = [];

    /** @var list<array{0: string, 1: string}> column, direction */
    public array $sort = [];

    /** @var array<string, list<string>> slug => the fields asked for */
    public array $fields = [];

    /** @var list<string> */
    public array $include = [];

    public int $page = 1;

    public int $perPage = 100;

    public static function forIndex(Request $request, Descriptor $d, Registry $registry): self
    {
        $q = new self;
        if ($request->has('include')) {
            throw ApiException::invalid('include_not_supported_on_index', 'include is only supported on a single record; a list shows foreign keys and links.');
        }
        $q->keys($request, self::INDEX_KEYS);
        $q->fields = self::fields($request, $registry);
        $q->filters = self::filters($request, $d);
        $q->sort = self::sort($request, $d);
        [$q->page, $q->perPage] = self::page($request, $d);

        return $q;
    }

    public static function forShow(Request $request, Descriptor $d, Registry $registry): self
    {
        $q = new self;
        $q->keys($request, self::SHOW_KEYS);
        $q->fields = self::fields($request, $registry);
        $q->include = self::include($request, $d);

        return $q;
    }

    /** Only the fields of the write's answer: a write takes no query parameters but `fields`. */
    public static function forWrite(Request $request, Registry $registry): self
    {
        $q = new self;
        $q->keys($request, ['fields']);
        $q->fields = self::fields($request, $registry);

        return $q;
    }

    /** @param  Builder<Model>  $query */
    public function applyFilters(Builder $query, Descriptor $d): void
    {
        foreach ($this->filters as $key => ['op' => $op, 'value' => $value]) {
            $column = $query->getModel()->qualifyColumn(strstr($key, '|', true) ?: $key);
            $op === 'in' ? $query->whereIn($column, $value) : $query->where($column, Descriptor::RANGE_OPERATORS[$op], $value);
        }
    }

    /** @param  Builder<Model>  $query */
    public function applySort(Builder $query): void
    {
        foreach ($this->sort as [$column, $direction]) {
            $query->orderBy($query->getModel()->qualifyColumn($column), $direction);
        }
        $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /** @return list<string>|null the fields asked for on a type; null = all */
    public function fieldsFor(string $slug): ?array
    {
        return $this->fields[$slug] ?? null;
    }

    private function keys(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_keys($request->query()), $allowed);
        if ($unknown !== []) {
            throw ApiException::invalid('unknown_parameter', 'Unknown query parameter: '.implode(', ', $unknown).'. Allowed here: '.implode(', ', $allowed).'.');
        }
    }

    /** @return array<string, list<string>> */
    private static function fields(Request $request, Registry $registry): array
    {
        $input = $request->query('fields');
        if ($input === null) {
            return [];
        }
        if (! is_array($input)) {
            throw ApiException::invalid('unknown_field', 'Use fields[<type>]=a,b, for example fields[repos]=owner,name.');
        }
        $fields = [];
        foreach ($input as $slug => $list) {
            $target = $registry->find((string) $slug);
            if ($target === null || ! is_string($list)) {
                throw ApiException::invalid('unknown_field', "fields[{$slug}]: no such type, or not a comma-separated list.");
            }
            $names = array_values(array_filter(array_map('trim', explode(',', $list)), fn ($n) => $n !== ''));
            $known = [...array_keys($target->attributes), ...array_keys($target->relationships), 'links'];
            $unknown = array_diff($names, $known);
            if ($unknown !== []) {
                throw ApiException::invalid('unknown_field', "fields[{$slug}]: unknown field ".implode(', ', $unknown).'.', ['fields' => array_values($unknown)]);
            }
            $fields[(string) $slug] = array_values(array_unique(['id', ...$names]));
        }

        return $fields;
    }

    /** @return array<string, array{op: string, value: mixed}> */
    private static function filters(Request $request, Descriptor $d): array
    {
        $input = $request->query('filter');
        if ($input === null) {
            return [];
        }
        if (! is_array($input)) {
            throw ApiException::invalid('unknown_filter', 'Use filter[<attribute>]=value.');
        }
        $filters = [];
        foreach ($input as $name => $value) {
            $name = (string) $name;
            if (! array_key_exists($name, $d->filters)) {
                throw ApiException::invalid('unknown_filter', "No filter '{$name}' on {$d->slug}. Filterable: ".(implode(', ', array_keys($d->filters)) ?: 'none').'.');
            }
            $attribute = $d->attributes[$name];
            if (is_array($value)) {
                foreach ($value as $op => $operand) {
                    if (! $d->filters[$name] || ! isset(Descriptor::RANGE_OPERATORS[$op]) || ! is_string($operand)) {
                        throw ApiException::invalid('invalid_filter_operator', "filter[{$name}][{$op}]: ".($d->filters[$name] ? 'use gte, gt, lte or lt.' : "{$name} takes no operators."));
                    }
                    $filters["{$name}|{$op}"] = ['op' => $op, 'value' => self::operand($attribute, $operand, $name)];
                }

                continue;
            }
            $values = array_map(fn (string $v) => self::operand($attribute, $v, $name), explode(',', (string) $value));
            $filters[$name] = ['op' => 'in', 'value' => $values];
        }

        return $filters;
    }

    private static function operand(Attribute $attribute, string $value, string $name): mixed
    {
        $bad = fn () => throw ApiException::invalid('invalid_filter_value', "filter[{$name}]: '{$value}' is not a valid {$attribute->type}.");

        return match ($attribute->type) {
            'integer' => preg_match('/^-?\d+$/', $value) ? (int) $value : $bad(),
            'number' => is_numeric($value) ? $value + 0 : $bad(),
            'boolean' => match (strtolower($value)) {
                'true', '1' => true,
                'false', '0' => false,
                default => $bad(),
            },
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $bad(),
            'datetime' => (function () use ($value, $bad) {
                try {
                    return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
                } catch (Throwable) {
                    return $bad();
                }
            })(),
            'enum' => in_array($value, $attribute->enum, true) ? $value : $bad(),
            default => $value,
        };
    }

    /** @return list<array{0: string, 1: string}> */
    private static function sort(Request $request, Descriptor $d): array
    {
        $input = $request->query('sort', $d->defaultSort);
        if ($input === null || $input === '') {
            return [];
        }
        if (! is_string($input)) {
            throw ApiException::invalid('unknown_sort', 'Use sort=name,-created_at.');
        }
        $sort = [];
        foreach (explode(',', $input) as $part) {
            $name = ltrim(trim($part), '-');
            if (! in_array($name, $d->sorts, true)) {
                throw ApiException::invalid('unknown_sort', "No sort '{$name}' on {$d->slug}. Sortable: ".(implode(', ', $d->sorts) ?: 'none').'.');
            }
            $sort[] = [$name, str_starts_with(trim($part), '-') ? 'desc' : 'asc'];
        }

        return $sort;
    }

    /** @return list<string> */
    private static function include(Request $request, Descriptor $d): array
    {
        $input = $request->query('include');
        if ($input === null || $input === '') {
            return [];
        }
        $names = is_string($input) ? array_map('trim', explode(',', $input)) : [];
        $unknown = array_diff($names, array_keys($d->relationships));
        if ($names === [] || $unknown !== []) {
            throw ApiException::invalid('unknown_include', 'No relationship '.implode(', ', $unknown ?: ['(empty)'])." on {$d->slug}. Relationships: ".(implode(', ', array_keys($d->relationships)) ?: 'none').'.');
        }

        return array_values(array_unique($names));
    }

    /** @return array{0: int, 1: int} */
    private static function page(Request $request, Descriptor $d): array
    {
        $default = $d->perPage ?? (int) config('rest-base.pagination.per_page', 100);
        $max = $d->maxPerPage ?? (int) config('rest-base.pagination.max_per_page', 1000);
        $number = function (string $key, int $fallback) use ($request): int {
            $value = $request->query($key);
            if ($value === null) {
                return $fallback;
            }
            // at most nine digits: a page number past that only overflows the offset (a 500), it never finds rows
            if (! is_string($value) || ! preg_match('/^\d{1,9}$/', $value) || (int) $value < 1) {
                throw ApiException::invalid('invalid_page', "{$key} must be a whole number from 1 to 999999999.");
            }

            return (int) $value;
        };

        return [$number('page', 1), min($number('per_page', $default), $max)];
    }
}
