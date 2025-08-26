<?php

namespace Flowra\Traits;

use App\Events\TransitionApplied;
use App\Exceptions\ApplyTransitionException;
use App\Models\State;
use App\Models\Transition;
use App\Models\TransitionHistory;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Staudenmeir\EloquentHasManyDeep\HasOneDeep;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;
use Throwable;

trait HasWorkflow
{
    use HasRelationships;

    // ================================ Relations ================================#
    public function transitionHistory(): MorphMany
    {
        return $this->morphMany(TransitionHistory::class, 'model');
    }

    // attribute necessary for loading workflow relation correctly
    public function workflowTypeKey(): Attribute
    {
        return Attribute::make(fn () => static::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class, 'model_type', 'workflow_type_key');
    }

    public function appliedToStates(): HasManyThrough
    {
        return $this->hasManyThrough(State::class, TransitionHistory::class, 'model_id', 'id', 'id', 'to_state_id')
            ->where('model_type', static::class);
    }

    public function appliedFromStates(): HasManyThrough
    {
        return $this->hasManyThrough(State::class, TransitionHistory::class, 'model_id', 'id', 'id', 'from_state_id')
            ->where('model_type', static::class);
    }

    /**
     * @warning: don't use this function in whereHas and withWhereHas methods, it will check in all states not current one
     *           instead when needed you can use whereCurrentState scope method.
     */
    public function currentState(Workflow|string|null $workflow = null): HasOneDeep
    {
        if (is_string($workflow)) {
            $workflow = Workflow::where('name', $workflow)->where('model_type', $this::class)->first([
                'id', 'name', 'model_type',
            ]);
        }

        if ($workflow === null) {
            $workflow = Workflow::where('model_type', $this::class)->first(['id', 'name', 'model_type']);
        }

        return $this->hasOneDeep(State::class, [TransitionHistory::class], ['model_id', 'id'], ['id', 'to_state_id'])
            ->where('model_transition_history.model_type', self::class)
            ->where('model_transition_history.workflow_id', $workflow->id)
            ->latest('model_transition_history.created_at')
            ->latest('model_transition_history.id');
    }

    /**
     * @throws Throwable
     */
    public function nextAllowedTransitions(?Workflow $workflow = null): ?Collection
    {
        $workflow = $workflow ?? $this->workflow;
        if (! $workflow) {
            $workflow = Workflow::where('model_type', $this::class)->first();
        }

        $this->validateWorkflow($workflow);

        return $this->currentState($workflow)->first()?->fromTransitions ?? new Collection([$workflow->firstTransition]);
    }

    /**
     * @throws Throwable
     */
    public function validateWorkflow(?Workflow $workflow): void
    {
        throw_if(! $workflow, new \Exception('There is no workflow assigned to this model type'));
        throw_if($workflow->model_type !== $this::class, new \Exception('Workflow doesn\'t belong to this type'));
    }

    // ================================ APPLY TRANSITION ================================#

    /**
     * @throws Throwable
     */
    public function go(Transition|string $transition, array $comment = []): Model
    {
        if (is_string($transition)) {
            $transition = Transition::where('name', $transition)->first();
        }

        if (! $transition) {
            throw new ApplyTransitionException(message('Transition not found, please check transition name or make sure to run workflow:sync command.')->severityError());
        }

        $this->validateTransition($transition);

        $transition = $this->transitionHistory()->create([
            'workflow_id' => $transition->workflow_id,
            'transition_id' => $transition->id,
            'from_state_id' => $transition->from_state_id,
            'to_state_id' => $transition->to_state_id,
            'comment' => $comment,
            'applied_by' => auth()->id(),
        ]);

        TransitionApplied::dispatchIf(
            $this->shouldFireTransitionEvent() && $transition,
            $this, $transition
        );

        return $transition;
    }

    /**
     * @throws Throwable
     */
    public function validateTransition(Transition $transition): void
    {
        // TODO: add auth check
        //        throw_if(auth()->user() && !auth()->user()->hasPermissionTo("use.{$transition->workflow->name}.{$transition->name}"),
        //            new \Exception("Auth user does not have permission to apply transition ($transition->name)."));

        $workflow = $transition->workflow;

        throw_if($workflow->model_type !== $this::class,
            new ApplyTransitionException(message("The workflow ({$workflow->label}) is not applicable to model type ($this::class).")->severityError()));

        throw_if(! $this->nextAllowedTransitions($workflow)?->contains($transition),
            new ApplyTransitionException(message("Transition ({$transition?->label}) is not allowed from current state ({$this->currentState($workflow)->first()?->label})")->severityError()));
    }

    public function shouldFireTransitionEvent(): bool
    {
        return property_exists($this, 'fireTransitionEvents') && $this->fireTransitionEvents;
    }

    public function getTransition($name, $workflowId)
    {
        return Transition::where('name', $name)
            ->where('workflow_id', $workflowId)->first();
    }

    /**
     * Check if a specific transition has been applied before
     *
     * @param  Transition|string  $transition  Transition object or name
     * @param  Workflow|null  $workflow  Optional workflow to check against
     * @return bool True if transition has been applied, false otherwise
     */
    public function hasTransitionBeenApplied(Transition|string $transition, string|Workflow|null $workflow = null): bool
    {
        if (is_string($transition)) {
            $transition = Transition::where('name', $transition)->first();
        }

        if (! $transition) {
            return false;
        }

        if (is_string($workflow)) {
            $workflow = Workflow::where('name', $workflow)->first();
        }

        if ($workflow === null) {
            $workflow = $transition->workflow;
        }

        return $this->transitionHistory()
            ->where('workflow_id', $workflow->id)
            ->where('transition_id', $transition->id)
            ->exists();
    }

    // ================================ Scopes ================================#
    public function scopeWhereCurrentState($query, string $stateName)
    {
        return $query->where(function ($query) use ($stateName) {
            $query->whereRaw('
                (
                    select s.name
                    from states s
                    inner join model_transition_history mth
                        on mth.to_state_id = s.id
                    where mth.model_id = farz_applications.id
                        and mth.model_type = ?
                    order by mth.created_at desc, mth.id desc
                    limit 1
                ) = ?
            ', [static::class, $stateName]);
        });
    }

    public function scopeOrWhereCurrentState($query, string $stateName)
    {
        return $query->orWhere(function ($query) use ($stateName) {
            $query->whereRaw('
                (
                    select s.name
                    from states s
                    inner join model_transition_history mth
                        on mth.to_state_id = s.id
                    where mth.model_id = farz_applications.id
                        and mth.model_type = ?
                    order by mth.created_at desc, mth.id desc
                    limit 1
                ) = ?
            ', [static::class, $stateName]);
        });
    }

    public function scopeWithWhereCurrentState($query, string $stateName)
    {
        return $query->whereCurrentState($stateName)->with('currentState');
    }

    public function scopeWhereNotCurrentState($query, string $stateName)
    {
        return $query->where(function ($query) use ($stateName) {
            $query->whereRaw('
            (
                select s.name
                from states s
                inner join model_transition_history mth
                    on mth.to_state_id = s.id
                where mth.model_id = farz_applications.id
                    and mth.model_type = ?
                order by mth.created_at desc, mth.id desc
                limit 1
            ) != ?
        ', [static::class, $stateName]);
        });
    }

    public function scopeOrWhereNotCurrentState($query, string $stateName)
    {
        return $query->orWhere(function ($query) use ($stateName) {
            $query->whereRaw('
            (
                select s.name
                from states s
                inner join model_transition_history mth
                    on mth.to_state_id = s.id
                where mth.model_id = farz_applications.id
                    and mth.model_type = ?
                order by mth.created_at desc, mth.id desc
                limit 1
            ) != ?
        ', [static::class, $stateName]);
        });
    }
}
