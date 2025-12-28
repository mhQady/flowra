<?php

namespace Flowra\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Status extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->table = config('flowra.tables.statuses', 'statuses');
        parent::__construct($attributes);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    protected function comment(): Attribute
    {
        return Attribute::make(
            get: static fn($value) => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            set: static fn($value) => json_encode($value, JSON_THROW_ON_ERROR)
        );
    }
}
