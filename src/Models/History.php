<?php

namespace Flowra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class History extends Model
{
    protected $table;
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        $this->table = config('flowra.tables.history', 'flowra_history');
        parent::__construct($attributes);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}