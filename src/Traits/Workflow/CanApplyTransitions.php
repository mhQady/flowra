<?php

namespace Flowra\Traits\Workflow;

use Flowra\DTOs\Jump;
use Flowra\DTOs\Transition;
use Flowra\Exceptions\ApplyJumpException;
use Flowra\Exceptions\ApplyTransitionException;
use Flowra\Models\Registry;
use Flowra\Models\Status;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnitEnum;

trait CanApplyTransitions
{
    use CanEvaluateGuards, CanExecuteActions;

    /**
     * @throws Throwable
     */
    public function apply(Transition $t): static
    {
//        $this->__evaluateGuards($t);
//
        $this->validateTransitionStructure($t);

        ########################
        # blocks parent if a child is running
//        $this->__beforeTransitionApply($t);

        $status = $this->__save($t);

        $this->hydrateStates($status);

        # spawns child OR completes parent
//        $this->__afterTransitionApplied($this->currentStatus(), $t->to);
        ########################

        $this->__executeActions($t);

        return $this;
    }

    /**
     * @throws Throwable
     */
    public function jumpTo(UnitEnum|string|int $state, string $resetName = 'reset'): static
    {
        [$toState, $fromState] = $this->__validateJumpApplicable($state);

        $resetObj = new Jump($resetName, $fromState, $toState, $this);

        $this->__save($resetObj);

        return $this;
    }

    /**
     * @param  Transition  $t
     * @param  array|null  $comment
     * @return void
     * @throws Throwable
     */
    private function __save(Transition $t): ?Status
    {
        return DB::transaction(function () use ($t) {

            $status = $this->__saveStatus($t);

            $this->__appendToRegistry($t);

//            if ($this->isBoundState($t->to)) {
//                $innerWorkflow = $this->innerWorkflows[$t->to->value];
////                dd($innerWorkflow);
//                $innerStartTransitionName = $innerWorkflow->startTransition;
//                $innerWorkflow->apply($innerWorkflow->{$innerStartTransitionName});
//            }

            return $status;
        });

    }

    private function isBoundState(UnitEnum $state): bool
    {
        return array_key_exists($state->value, $this->innerWorkflows);
    }


    private function validateTransitionStructure(Transition $t): void
    {
        if (!$this->model->exists) {
            throw new ApplyTransitionException(__('flowra::flowra.model_exist'));
        }

        if (!$this->isWorkflowRegisteredForModel()) {
            throw new ApplyTransitionException(
                __('flowra::flowra.workflow_not_registered_for_model',
                    ['workflow' => $this::class, 'model' => $this->model::class])
            );
        }

        # check if transition is already defined in workflow #
        if (!in_array($t->key, array_keys($this->transitions()))) {
            throw new ApplyTransitionException(__('flowra::flowra.transition_not_registered_for_workflow',
                ['transition' => $t->key, 'workflow' => $this::class]));
        }
        # determine current (if not started, you may treat "from" as the expected initial) #
        if (($current = $this->currentState?->value ?? $t->from->value) !== $t->from->value) {
            throw new ApplyTransitionException(__('flowra::flowra.transition_not_applicable',
                ['transition' => $t->key, 'current' => $current, 'from' => $t->from->value]));
        }
    }

    private function isWorkflowRegisteredForModel(): bool
    {
        if (isset($this->model->workflows) && in_array($this::class, $this->model->workflows))
            return true;

        return false;
    }

    /**
     * @param  UnitEnum|int|string  $state
     * @return array
     */
    private function __validateJumpApplicable(UnitEnum|int|string $state): array
    {
        if (!($state instanceof UnitEnum)) {
            $state = $this->statesClass::tryFrom($state);
        }

        if (!($state instanceof $this->statesClass)) {
            throw new ApplyJumpException('State is not valid, state must be of type ('.$this->statesClass::class.')');
        }

        if (!($fromStatus = $this->currentState)) {
            throw new ApplyJumpException('From state is not valid, state must not be (<fg=yellow;options=bold>null</>) on jump');
        }

        return [$state, $fromStatus];
    }

    /**
     * @param  Transition  $t
     * @return void
     */
    private function __saveStatus(Transition $t): Status
    {
        return Status::query()->updateOrCreate(
            [
                'owner_type' => $this->model->getMorphClass(),
                'owner_id' => $this->model->getKey(),
                'workflow' => $this::class,
            ],
            [
                'transition' => $t->key,
                'from' => $t->from->value,
                'to' => $t->to->value,
//                'comment' => $t->comment,
                // 'applied_by' => $t->appliedBy,
                'type' => $t->type,
//                'parent_workflow' => $this->parentWorkflow,
//                'bound_state' => $this->boundState,
            ]
        );
        //        $this->__afterTransitionApplied($this->currentStatus(), $t->to);
    }


    /**
     * @param  Transition  $t
     * @return void
     */
    private function __appendToRegistry(Transition $t): void
    {
        Registry::query()->create([
            'owner_type' => $this->model->getMorphClass(),
            'owner_id' => $this->model->getKey(),
            'workflow' => $this::class,
            'transition' => $t->key,
            'from' => $t->from->value,
            'to' => $t->to->value,
//            'comment' => $t->comment,
            // 'applied_by' => $t->appliedBy,
            'type' => $t->type,
        ]);
    }

}
