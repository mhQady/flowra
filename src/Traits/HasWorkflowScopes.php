<?php

namespace Flowra\Traits;

use BackedEnum;
use Flowra\DTOs\BulkTransitionResult;
use Flowra\DTOs\Transition;
use Flowra\Exceptions\ApplyTransitionException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use UnitEnum;

trait HasWorkflowScopes
{

    protected static function bootHasWorkflowScopes(): void
    {
        static::registerScopesMacros();
        static::registerTransitionMacros();
    }

    /**
     * Filter by current status (IN list) for a given flow (alias or FQCN).
     *
     * @param  Builder  $query
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @param  string|UnitEnum|array<string|UnitEnum>  $states  single or array; enums allowed
     * @return Builder
     */
    public function scopeWhereCurrentStatus(Builder $query, string $workflow, string|UnitEnum|array $states): Builder
    {
        [$relation, $in] = $this->normalizeWorkflowAndStates($workflow, $states);

        return $query->whereHas($relation, fn($q) => $q->whereIn('to', $in));
    }

    /**
     * OR filter by current status (IN list) for a given flow (alias or FQCN).
     *
     * @param  Builder  $query
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @param  string|UnitEnum|array<string|UnitEnum>  $states  single or array; enums allowed
     * @return Builder
     */
    public function scopeOrWhereCurrentStatus(Builder $query, string $workflow, string|UnitEnum|array $states): Builder
    {
        return $query->orWhere(fn($q) => $this->scopeWhereCurrentStatus($q, $workflow, $states));
    }

    /**
     * Exclude rows whose current status (for a workflow) is in given list.
     *
     * @param  Builder  $query
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @param  string|UnitEnum|array<string|UnitEnum>  $states  single or array; enums allowed
     * @return Builder
     */
    public function scopeWhereNotCurrentStatus(Builder $query, string $workflow, string|UnitEnum|array $states): Builder
    {
        [$relation, $in] = $this->normalizeWorkflowAndStates($workflow, $states);

        return $query->whereDoesntHave($relation, fn($q) => $q->whereIn('to', $in));
    }

    /**
     * OR exclude rows whose current status (for a workflow) is in given list.
     *
     * @param  Builder  $query
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @param  string|UnitEnum|array<string|UnitEnum>  $states  single or array; enums allowed
     * @return Builder
     */
    public function scopeOrWhereNotCurrentStatus(
        Builder $query,
        string $workflow,
        string|UnitEnum|array $states
    ): Builder {
        return $query->orWhere(fn($q) => $this->scopeWhereNotCurrentStatus($q, $workflow, $states));
    }

    /**
     * Eager-load the flow's status but only if it matches given states.
     * (Does not filter the parent rows; just constrains the relation.)
     *
     * @param  Builder  $query
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @param  string|UnitEnum|array<string|UnitEnum>  $states  single or array; enums allowed
     * @return Builder
     */
    public function scopeWithWhereCurrentStatus(
        Builder $query,
        string $workflow,
        string|UnitEnum|array $states
    ): Builder {
        [$relation, $in] = $this->normalizeWorkflowAndStates($workflow, $states);
        return $query->withWhereHas($relation, fn($q) => $q->whereIn('to', $in));;
    }

    private static function registerScopesMacros(): void
    {
        foreach (static::appliedWorkflows() as $class) {

            $alias = Str::pascal(class_basename($class));

            Builder::macro("where{$alias}CurrentStatus",
                fn(string|UnitEnum|array $states) => $this->whereCurrentStatus($class, $states));

            Builder::macro("orWhere{$alias}CurrentStatus",
                fn(string|UnitEnum|array $states) => $this->orWhereCurrentStatus($class, $states));


            Builder::macro("whereNot{$alias}CurrentStatus",
                fn(string|UnitEnum|array $states) => $this->whereNotCurrentStatus($class, $states));

            Builder::macro("orWhereNot{$alias}CurrentStatus",
                fn(string|UnitEnum|array $states) => $this->orWhereNotCurrentStatus($class, $states));

            Builder::macro("withWhere{$alias}CurrentStatus",
                fn(string|UnitEnum|array $states) => $this->withWhereCurrentStatus($class, $states));
        }
    }

    private static function registerTransitionMacros(): void
    {
        if (!Builder::hasGlobalMacro('applyTransition')) {
            Builder::macro('applyTransition', function (string|Transition $transition, array $options = []) {
                /** @var Builder $this */
                $model = $this->getModel();
                $workflows = method_exists($model, 'appliedWorkflows') ? $model::appliedWorkflows() : [];

                if (empty($workflows)) {
                    throw new ApplyTransitionException('No workflows registered for model '.get_class($model));
                }

                if (count($workflows) > 1) {
                    throw new ApplyTransitionException('Multiple workflows registered; use apply{Alias}Transition macro.');
                }

                $workflowClass = $workflows[0];
                $collection = $this->get();

                if ($collection->isEmpty()) {
                    return new BulkTransitionResult();
                }

                return new $workflowClass($collection->first())->applyMany($collection, $transition, $options);
            });
        }

        if (!Collection::hasMacro('applyTransition')) {
            Collection::macro('applyTransition', function (string|Transition $transition, array $options = []) {
                /** @var Collection $this */
                if ($this->isEmpty()) {
                    return new BulkTransitionResult();
                }

                $first = $this->first();
                $workflows = method_exists($first, 'appliedWorkflows') ? $first::appliedWorkflows() : [];

                if (empty($workflows)) {
                    throw new ApplyTransitionException('No workflows registered for model '.get_class($first));
                }

                if (count($workflows) > 1) {
                    throw new ApplyTransitionException('Multiple workflows registered; use apply{Alias}Transition macro.');
                }

                $workflowClass = $workflows[0];
                $workflow = new $workflowClass($first);

                return $workflow->applyMany($this->all(), $transition, $options);
            });
        }

        foreach (static::appliedWorkflows() as $class) {
            $alias = Str::pascal(class_basename($class));
            $macroName = "apply{$alias}Transition";

            if (!Builder::hasGlobalMacro($macroName)) {
                Builder::macro($macroName,
                    function (string|Transition $transition, array $options = []) use ($class) {
                        /** @var Builder $this */
                        $collection = $this->get();

                        if ($collection->isEmpty()) {
                            return new BulkTransitionResult();
                        }

                        $workflow = new $class($collection->first());

                        return $workflow->applyMany($collection, $transition, $options);
                    });
            }

            if (!Collection::hasMacro($macroName)) {
                Collection::macro($macroName,
                    function (string|Transition $transition, array $options = []) use ($class) {
                        /** @var Collection $this */
                        if ($this->isEmpty()) {
                            return new BulkTransitionResult();
                        }

                        $workflow = new $class($this->first());

                        return $workflow->applyMany($this->all(), $transition, $options);
                    });
            }
        }
    }

    /**
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @param  string|UnitEnum|array  $states
     * @return array
     */
    private function normalizeWorkflowAndStates(string $workflow, string|UnitEnum|array $states): array
    {
        [$relation, $workflowClass] = $this->workflowRelationInfo($workflow);

        $list = is_array($states) ? $states : [$states];

        $in = [];

        foreach ($list as $state) {
            $in = array_merge($in, $this->expandStateForWorkflow($workflowClass, $state));
        }

        $in = array_values(array_unique($in));

        return [$relation, $in];
    }

    /**
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @return string
     */
    private function workflowRelationInfo(string $workflow): array
    {
        $workflowClass = $this->resolveWorkflowClass($workflow);
        $relation = $this->workflowRelationName($workflowClass ?? $workflow);

        return [$relation, $workflowClass];
    }

    private function workflowRelationName(string $workflow): string
    {
        $isClass = class_exists($workflow);
        $alias = $isClass ? Str::camel(class_basename($workflow)) : Str::camel($workflow);

        return $alias.'Status';
    }

    private function resolveWorkflowClass(string $workflow): ?string
    {
        if (class_exists($workflow)) {
            return $workflow;
        }

        if (!method_exists($this, 'appliedWorkflows')) {
            return null;
        }

        $target = Str::camel($workflow);

        foreach (static::appliedWorkflows() as $class) {
            $alias = Str::camel(class_basename($class));

            if ($alias === $target) {
                return $class;
            }
        }

        return null;
    }

    private function expandStateForWorkflow(?string $workflowClass, string|UnitEnum $state): array
    {
        $value = $this->stringifyState($state);

        if (!$workflowClass || !method_exists($workflowClass, 'stateParentGroup')) {
            if ($workflowClass && method_exists($workflowClass, 'stateGroupChildren')) {
                return array_map(
                    fn(array $child) => $child['value'] ?? $child['key'] ?? $value,
                    $workflowClass::stateGroupChildren($state)
                );
            }

            return [$value];
        }

        $parent = $workflowClass::stateParentGroup($state);

        if ($parent) {
            $parentState = $parent['state'] ?? [];

            return [$parentState['value'] ?? $parentState['key'] ?? $value];
        }

        $children = method_exists($workflowClass, 'stateGroupChildren')
            ? $workflowClass::stateGroupChildren($state)
            : [];

        if (count($children) > 0) {
            return array_map(
                fn(array $child) => $child['value'] ?? $child['key'] ?? $value,
                $children
            );
        }

        return [$value];
    }

    private function stringifyState(string|UnitEnum $state): string
    {
        if ($state instanceof BackedEnum) {
            return $state->value;
        }

        if ($state instanceof UnitEnum) {
            return $state->name;
        }

        return (string) $state;
    }
}
