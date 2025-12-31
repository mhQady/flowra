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
        $this->evaluateGuards($t);

        $this->validateTransitionStructure($t);

        $status = $this->save($t);

        $this->hydrateStates($status);

        $this->__executeActions($t);

        return $this;
    }

    /**
     * @throws Throwable
     */
    public function jumpTo(
        UnitEnum|string|int $state,
        string $jumpName = 'reset',
        ?int $appliedBy = null,
    ): static {
        [$toState, $fromState] = $this->validateJumpApplicable($state);

        $resetObj = new Jump($jumpName, $fromState, $toState, $this, $appliedBy);

        $this->save($resetObj);

        return $this;
    }

    /**
     * @param  Transition  $t
     * @return Status|null
     * @throws Throwable
     */
    private function save(Transition $t): ?Status
    {
        return DB::transaction(function () use ($t): Status {

            $status = $this->saveStatus($t);

            $this->appendToRegistry($t);

            return $status;
        });

    }

    private function validateTransitionStructure(Transition $t): void
    {
        if (!$this->model?->exists) {
            throw new ApplyTransitionException(__('flowra::flowra.record_not_exist', ['model' => $this->model::class]));
        }

        if (!$this->isWorkflowRegisteredForModel()) {
            throw new ApplyTransitionException(
                __(
                    'flowra::flowra.workflow_not_registered_for_model',
                    ['workflow' => $this::class, 'model' => $this->model::class]
                )
            );
        }

        # check if transition is already defined in workflow #
        if (!array_key_exists($t->key, $this->transitions)) {
            throw new ApplyTransitionException(__(
                'flowra::flowra.transition_not_registered_for_workflow',
                ['transition' => $t->key, 'workflow' => $this::class]
            ));
        }
        # determine current (if not started, you may treat "from" as the expected initial) #
        if (($current = $this->currentState?->value ?? $t->from->value) !== $t->from->value) {
            throw new ApplyTransitionException(__(
                'flowra::flowra.transition_not_applicable',
                ['transition' => $t->key, 'current' => $current, 'from' => $t->from->value]
            ));
        }
    }

    private function isWorkflowRegisteredForModel(): bool
    {
        $appliedWorkflows = $this->model::appliedWorkflows();

        if (isset($appliedWorkflows) && in_array($this::class, $appliedWorkflows, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  UnitEnum|int|string  $state
     * @return array
     */
    private function validateJumpApplicable(UnitEnum|int|string $state): array
    {
        if (!($state instanceof UnitEnum)) {
            $state = $this->statesEnum::tryFrom($state);
        }

        if (!($state instanceof $this->statesEnum)) {
            throw new ApplyJumpException(__('flowra::flowra.state_required_on_jump',
                ['state' => $this->statesEnum]));
        }

        if (!($fromStatus = $this->currentState)) {
            throw new ApplyJumpException('From state is not valid, state must not be (null) on jump');
        }

        return [$state, $fromStatus];
    }

    /**
     * @param  Transition  $t
     * @return void
     */
    private function saveStatus(Transition $t): Status
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
                'comment' => $t->comments,
                'applied_by' => $t->appliedBy,
                'type' => $t->type,
            ]
        );
    }


    /**
     * @param  Transition  $t
     * @return void
     */
    private function appendToRegistry(Transition $t): void
    {
        Registry::query()->create([
            'owner_type' => $this->model->getMorphClass(),
            'owner_id' => $this->model->getKey(),
            'workflow' => $this::class,
            'transition' => $t->key,
            'from' => $t->from->value,
            'to' => $t->to->value,
            'comment' => $t->comments,
            'applied_by' => $t->appliedBy,
            'type' => $t->type,
        ]);
    }

}
