<?php

namespace Flowra\Models;

use Flowra\Concretes\HasWorkflow;
use Flowra\Contracts\HasWorkflowContract;
use Flowra\Flows\MainFlow\MainWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * @property MainWorkflow $mainWorkflow
 */
class Context extends Model implements HasWorkflowContract
{
    use HasWorkflow;

    protected $guarded = ['id'];
    public static array $workflows = [
        MainWorkflow::class,
    ];
}