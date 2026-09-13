<?php

namespace Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}
