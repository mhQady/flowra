<?php

namespace Flowra\Models;

use Flowra\Concretes\HasWorkflow;
use Flowra\Contracts\HasWorkflowContract;
use Flowra\Flows\MainWorkflow\MainWorkflow;
use Illuminate\Database\Eloquent\Model;

/**
 * @property MainWorkflow $mainWorkflow
 */
class Context extends Model implements HasWorkflowContract
{
    use HasWorkflow;

    protected $guarded = ['id'];

    protected $casts = [
        'test' => 'array'
    ];

    public static array $workflows = [
        MainWorkflow::class,
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer'
        ];
    }
}