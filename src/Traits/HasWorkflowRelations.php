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
        static::registerWorkflowsRelations();
    }

    /**
     * Register relations for applied workflows.
     */
    private static function registerWorkflowsRelations(): void
    {
        foreach (static::appliedWorkflows() as $class) {

            $alias = Str::camel(class_basename($class));

            static::resolveRelationUsing($alias.'Status', function (Model $model) use ($class) {
                return $model->morphOne(Status::class, 'owner')->where('workflow', $class);
            });

            static::resolveRelationUsing($alias.'Registry', function (Model $model) use ($class) {
                return $model->morphMany(Registry::class, 'owner')->where('workflow', $class);
            });
        }
    }

    /**
     * Get all statuses records despite the workflow type.
     *
     * @return MorphMany
     */
    public function statuses(): MorphMany
    {
        return $this->morphMany(Status::class, 'owner');
    }

    /**
     * Get all registry records despite the workflow type.
     *
     * @return MorphMany
     */
    public function registry(): MorphMany
    {
        return $this->morphMany(Registry::class, 'owner');
    }
}