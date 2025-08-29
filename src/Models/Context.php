<?php

namespace Flowra\Models;

use Flowra\Contracts\HasFlowContract;
use Flowra\Flows\MainFlow\MainFlow;
use Flowra\Traits\HasFlow;
use Illuminate\Database\Eloquent\Model;

/**
 * @property MainFlow $mainFlow
 */
class Context extends Model implements HasFlowContract
{
    use HasFlow;

    protected $table = 'contexts';

    protected $fillable = ['id'];

    public array $flows = [MainFlow::class];

}