<?php

namespace Flowra\Models;

use Flowra\Contracts\HasWorkflowContract;
use Flowra\Flows\MainFlow\MainWorkflow;
use Flowra\Traits\HasWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * @property MainWorkflow $mainWorkflow
 */
class Context extends Model implements HasWorkflowContract
{
    use HasWorkflow;

    protected $table = 'contexts';

    protected $fillable = ['id'];

    public array $workflows = [
        \Flowra\Flows\MainFlow\MainWorkflow::class,
        \App\Workflows\Test2Workflow\Test2Workflow::class
    ];

}