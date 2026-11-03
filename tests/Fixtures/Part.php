<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only, like a history row. */
class Part extends Model
{
    protected $fillable = ['thing_id', 'label'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Parts are append-only.'));
        static::deleting(fn () => throw new LogicException('Parts are never deleted.'));
    }

    public function thing(): BelongsTo
    {
        return $this->belongsTo(Thing::class);
    }
}
