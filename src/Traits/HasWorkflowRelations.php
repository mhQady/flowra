<?php

namespace Flowra\Traits;

use Flowra\Support\WorkflowModels;
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
                return $model->morphOne(WorkflowModels::status(), 'owner')->where('workflow', $class);
            });

            static::resolveRelationUsing($alias.'Registry', function (Model $model) use ($class) {
                return $model->morphMany(WorkflowModels::registry(), 'owner')->where('workflow', $class);
            });

            // The read views, registered the same way so they sit beside the relations above:
            // $order->orderWorkflowRegistryView()->view('customer')->latest()->get(). Eloquent hands a
            // resolver only the model, so each starts from the default view and view() switches.
            // They are reads, not relations: call them as methods, never with() or as a property.
            static::resolveRelationUsing($alias.'RegistryView', function (Model $model) use ($alias) {
                return $model->{$alias}->registryView();
            });

            static::resolveRelationUsing($alias.'StatusView', function (Model $model) use ($alias) {
                return $model->{$alias}->statusView();
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
        return $this->morphMany(WorkflowModels::status(), 'owner');
    }

    /**
     * Get all registry records despite the workflow type.
     *
     * @return MorphMany
     */
    public function registry(): MorphMany
    {
        return $this->morphMany(WorkflowModels::registry(), 'owner');
    }
}