<?php

namespace Flowra\Models;

use Flowra\Concretes\HasWorkflow;
use Flowra\Contracts\HasWorkflowContract;
use Flowra\Flows\FillAppDataWorkflow\FillAppDataWorkflow;
use Illuminate\Database\Eloquent\Model;

class Context extends Model implements HasWorkflowContract
{
    use HasWorkflow;

    protected $guarded = ['id'];

    public array $workflows = [
        FillAppDataWorkflow::class,
    ];
}