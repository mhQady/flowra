<?php

namespace Flowra\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

trait HasWorkflowScopes
{
    /**
     * Filter by current status (IN list) for a given flow (alias or FQCN).
     *
     * @param  Builder  $query
     * @param  string|class-string  $flow  e.g. 'mainFlow' or \Flowra\Flows\MainWorkflow\MainWorkflow::class
     * @param  string|UnitEnum|array<string|UnitEnum>  $states  single or array; enums allowed
     * @return Builder
     */
    public function scopeWhereCurrentStatus(Builder $query, string $workflow, string|UnitEnum|array $states): Builder
    {
        [$relation, $in] = $this->normalizeWorkflowAndStates($workflow, $states);

        return $query->whereHas($relation, fn($q) => $q->whereIn('state', $in));
    }

    public function scopeOrWhereCurrentStatus(Builder $query, string $workflow, string|UnitEnum|array $states): Builder
    {
        return $query->orWhere(fn($q) => $this->scopeWhereCurrentStatus($q, $workflow, $states));
    }

    /**
     * Exclude rows whose current status (for a flow) is in given list.
     */
    public function scopeWhereNotCurrentStatus(Builder $query, string $workflow, string|UnitEnum|array $states): Builder
    {
        [$relation, $in] = $this->normalizeWorkflowAndStates($workflow, $states);

        return $query->whereDoesntHave($relation, fn($q) => $q->whereIn('state', $in));
    }

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
     */
    public function scopeWithWhereCurrentStatus(
        Builder $query,
        string $workflow,
        string|UnitEnum|array $states
    ): Builder {
        [$relation, $in] = $this->normalizeWorkflowAndStates($workflow, $states);

        return $query->with([$relation => fn($q) => $q->whereIn('state', $in)]);
    }

    /**
     * Helpers
     */
    protected function normalizeWorkflowAndStates(string $workflow, string|UnitEnum|array $states): array
    {
        $relation = $this->workflowRelationName($workflow);
        $list = is_array($states) ? $states : [$states];

        // Support backed enums or plain strings
        $in = array_map(function ($s) {
            if ($s instanceof BackedEnum) {
                return $s->value;
            }
            if ($s instanceof UnitEnum) {
                // Pure (non-backed) enum → use its name
                return $s->name;
            }
            return (string) $s;
        }, $list);

        return [$relation, $in];
    }

    /**
     * Accept alias ('mainFlow') or FQCN and map to relation name like 'mainFlowStatus'.
     */
    protected function workflowRelationName(string $workflow): string
    {
        $isClass = class_exists($workflow);
        $alias = $isClass ? Str::camel(class_basename($workflow)) : Str::camel($workflow);

        return $alias.'Status';
    }
}