<?php

namespace Flowra\Models;

use Flowra\Contracts\HasWorkflowContract;
use Flowra\Flows\MainFlow\MainFlow;
use Flowra\Traits\HasWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * @property MainFlow $mainFlow
 */
class Context extends Model implements HasWorkflowContract
{
    use HasWorkflow;

    protected $table = 'contexts';

    protected $fillable = ['id'];

    public array $flows = [
        \Flowra\Flows\MainFlow\MainFlow::class
    ];

}