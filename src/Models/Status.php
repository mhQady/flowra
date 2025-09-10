<?php

namespace Flowra\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;


class Status extends Model
{
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
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value)
        );
    }
}