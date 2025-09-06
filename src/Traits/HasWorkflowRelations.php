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
        static::__registerWorkflowsRelations();
    }

    private static function __registerWorkflowsRelations(): void
    {
        foreach ((new static)->workflows as $workflowClass) {

            $alias = Str::camel(class_basename($workflowClass));

            static::resolveRelationUsing($alias.'Status', function (Model $model) use ($workflowClass) {
                return $model->morphOne(Status::class, 'owner')->where('workflow', $workflowClass);
            });

            static::resolveRelationUsing($alias.'Registry', function (Model $model) use ($workflowClass) {
                return $model->morphMany(Status::class, 'owner')->where('workflow', $workflowClass);
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