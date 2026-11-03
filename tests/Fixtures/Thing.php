<?php

namespace Ignited\RestBase\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Thing extends Model
{
    protected $fillable = ['owner_id', 'name', 'code', 'notes', 'status', 'is_active', 'due_on', 'meta', 'api_key', 'weight'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['status' => Status::class, 'is_active' => 'boolean', 'due_on' => 'date', 'meta' => 'array', 'weight' => 'float'];
    }

    protected static function booted(): void
    {
        static::saving(function (Thing $thing) {
            if ($thing->name === 'forbidden') {
                throw new LogicException('A thing may not be called forbidden.');
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }
}
