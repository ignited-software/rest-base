<?php

namespace Ignited\RestBase\Http;

use Ignited\RestBase\ApiException;
use Ignited\RestBase\Descriptor;
use Ignited\RestBase\Registry;
use Ignited\RestBase\Relationship;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Turns records into their wire form, always through a descriptor: only allowlisted attributes, never the model's
 * own toArray(). A list shows the summary (no show-only attributes, no embeds); a record shows everything, with
 * its belongs-to records embedded (as their own summary) and has-many records when asked for with ?include.
 * An embedded record is read through its own resource's query, role check and authorize('show'), so an embed
 * never shows a row or a field the caller could not read directly.
 */
final class Serializer
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Request $request,
        private readonly Roles $roles,
    ) {}

    /** @return array<string, mixed> */
    public function record(Descriptor $d, Model $model, bool $detailed, QueryParams $q): array
    {
        $fields = $q->fieldsFor($d->slug);
        $wants = fn (string $name) => $fields === null || in_array($name, $fields, true);
        $out = [];
        foreach ($d->visible($detailed) as $attribute) {
            if ($wants($attribute->name)) {
                $out[$attribute->name] = $attribute->read($model);
            }
        }
        if ($detailed) {
            foreach ($d->relationships as $name => $relation) {
                if (($relation->isToOne() || in_array($name, $q->include, true)) && $wants($name) && $this->readable($relation->resource)) {
                    $out[$name] = $this->embed($relation, $model, $q);
                }
            }
        }
        if ($wants('links')) {
            $out['links'] = $this->links($d, $model);
        }

        return $out;
    }

    /** @return array<string, string> */
    public function links(Descriptor $d, Model $model): array
    {
        $links = $d->has('show') ? ['self' => $this->url($d->slug, $model->getKey())] : [];
        foreach ($d->relationships as $name => $relation) {
            $target = $this->registry->descriptor($relation->resource);
            if ($relation->isToOne()) {
                $key = $model->getAttribute($relation->foreignKey);
                if ($key !== null && $target->has('show')) {
                    $links[$name] = $this->url($target->slug, $key);
                }
            } elseif ($relation->foreignKey !== null && $target->has('index')) {
                $links[$name] = $this->url($target->slug).'?'.http_build_query(['filter' => [$relation->foreignKey => $model->getKey()]]);
            }
        }

        return $links;
    }

    public function url(string $slug, int|string|null $id = null): string
    {
        $path = trim((string) config('rest-base.prefix'), '/').'/'.$slug.($id !== null ? '/'.rawurlencode((string) $id) : '');

        return URL::to($path);
    }

    private function readable(string $slug): bool
    {
        $target = $this->registry->descriptor($slug);

        return $target->has('show') && $this->roles->allows($this->request, $target, 'show');
    }

    /** @return array<string, mixed>|list<array<string, mixed>>|null */
    private function embed(Relationship $relation, Model $model, QueryParams $q): ?array
    {
        $target = $this->registry->descriptor($relation->resource);
        $query = $this->registry->resource($relation->resource)->query($this->request);

        if ($relation->isToOne()) {
            $key = $model->getAttribute($relation->foreignKey);
            $related = $key === null ? null : $query->whereKey($key)->first();

            return $related === null || ! $this->permits($relation->resource, $related) ? null : $this->record($target, $related, false, $q);
        }

        $limit = $target->maxPerPage ?? (int) config('rest-base.pagination.max_per_page', 1000);
        if ($relation->foreignKey !== null) {
            $query->where($query->getModel()->qualifyColumn($relation->foreignKey), $model->getKey());
        } else {
            $related = $model->{$relation->method()}()->getRelated();
            $query->whereIn($related->getQualifiedKeyName(), $model->{$relation->method()}()->limit($limit)->pluck($related->getQualifiedKeyName()));
        }

        return $query->orderBy($query->getModel()->getQualifiedKeyName())->limit($limit)->get()
            ->filter(fn (Model $child) => $this->permits($relation->resource, $child))
            ->map(fn (Model $child) => $this->record($target, $child, false, $q))->values()->all();
    }

    /** The target resource's own per-record refusal (authorize 'show') holds for an embed: a refused record is left out. */
    private function permits(string $slug, Model $related): bool
    {
        try {
            $this->registry->resource($slug)->authorize('show', $this->request, $related);
        } catch (AuthorizationException|HttpExceptionInterface) {
            return false;
        } catch (ApiException $e) {
            if (in_array($e->status, [403, 404], true)) {
                return false;
            }
            throw $e;
        }

        return true;
    }
}
