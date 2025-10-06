<?php

namespace Flowra\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

trait HasWorkflowScopes
{

    protected static function bootHasWorkflowScopes(): void
    {
        static::registerScopesMacros();
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
        return $query->with([$relation => fn($q) => $q->whereIn('to', $in)]);
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

    /**
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @param  string|UnitEnum|array  $states
     * @return array
     */
    private function normalizeWorkflowAndStates(string $workflow, string|UnitEnum|array $states): array
    {
        $relation = $this->workflowRelationName($workflow);

        $list = is_array($states) ? $states : [$states];

        $in = array_map(function ($s) {
            if ($s instanceof BackedEnum) {
                return $s->value;
            }
            if ($s instanceof UnitEnum) {
                return $s->name;
            }
            return (string) $s;
        }, $list);

        return [$relation, $in];
    }

    /**
     * @param  string|class-string  $workflow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @return string
     */
    private function workflowRelationName(string $workflow): string
    {
        $isClass = class_exists($workflow);
        $alias = $isClass ? Str::camel(class_basename($workflow)) : Str::camel($workflow);

        return $alias.'Status';
    }
}