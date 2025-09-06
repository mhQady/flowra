<?php

namespace Flowra\Traits;

use Flowra\Models\Registry;
use Flowra\Models\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Str;

trait HasWorkflowRelations
{
    use HasWorkflowScopes;

    protected static function bootHasWorkflowRelations(): void
    {
        static::__registerFlowsRelations();
    }

    private static function __registerFlowsRelations(): void
    {
        foreach ((new static)->flows as $flowClass) {

            $alias = Str::camel(class_basename($flowClass));

            static::resolveRelationUsing($alias.'Status', function (Model $model) use ($flowClass) {
                return $model->morphOne(Status::class, 'owner')->where('workflow', $flowClass);
            });

            static::resolveRelationUsing($alias.'Registry', function (Model $model) use ($flowClass) {
                return $model->morphMany(Status::class, 'owner')->where('workflow', $flowClass);
            });
        }
    }


    public function registry(): MorphMany
    {
        return $this->morphMany(Registry::class, 'owner');
    }

    public function statuses()
    {
        return $this->morphMany(Status::class, 'owner');
    }
}