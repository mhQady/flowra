<?php

namespace Tests\Fixtures\Models;

use Flowra\Contracts\HasWorkflowContract;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model implements HasWorkflowContract
{
    public $timestamps = false;

    protected $guarded = [];
}
