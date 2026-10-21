<?php

namespace Tests\Fixtures\Models;

use Flowra\Concretes\HasWorkflow;
use Flowra\Contracts\HasWorkflowContract;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Workflows\StatusWorkflow\StatusWorkflow;

/**
 * A model wired the way a host application wires one: HasWorkflow, so the workflow cast and the
 * `statusWorkflowStatus` / `statusWorkflowRegistry` relations are registered.
 */
class Application extends Model implements HasWorkflowContract
{
    use HasWorkflow;

    public $timestamps = false;

    protected $table = 'applications';

    protected $guarded = [];

    protected static array $workflows = [
        StatusWorkflow::class,
    ];
}
