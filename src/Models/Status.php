<?php

namespace Flowra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;


/**
 * @property string $to
 */
class Status extends Model
{
    protected $table;
    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $this->table = config('flowra.tables.statuses', 'flowra_statuses');
        parent::__construct($attributes);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}