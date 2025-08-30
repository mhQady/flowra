<?php

namespace Flowra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Registry extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->table = config('flowra.tables.registry', 'statuses_registry');
        parent::__construct($attributes);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}