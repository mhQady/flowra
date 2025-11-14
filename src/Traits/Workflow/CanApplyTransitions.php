<?php

namespace Flowra\Traits\Workflow;

use Flowra\DTOs\Jump;
use Flowra\DTOs\Transition;
use Flowra\Exceptions\ApplyJumpException;
use Flowra\Exceptions\ApplyTransitionException;
use Flowra\Models\Registry;
use Flowra\Models\Status;
use Str;
use Throwable;
use UnitEnum;

trait CanApplyTransitions
{
    use CanEvaluateGuards;
    use CanExecuteActions;

    /**
     * @throws Throwable
     */
    public function apply(Transition $t): static
    {
        //        try {
        //
        //            DB::beginTransaction();

        $this->validateTransitionStructure($t);

        $this->__evaluateGuards($t);

        ########################
        # (SUBFLOW related) blocks parent if a child is running
        $this->checkSubflowBeforeApplyTransition($t);

        $status = $this->__save($t);

        # (SUBFLOW related) start or exit subflow.
        if ($status) {
            $this->checkSubflowAfterApplyTransition($t, $status);
        }
        ########################

        $this->__executeActions($t);

        //            DB::commit();

        $this->hydrateStates($status);

        //        } catch (Exception $e) {
        //            DB::rollBack();
        //            throw $e;
        //        }

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


        $status = $this->__saveStatus($t);

        if ($status) {
            $this->__appendToRegistry($t, $status);
        }

        return $status;

    }

    private function isBoundState(UnitEnum $state): bool
    {
        return array_key_exists($state->value, $this->subflows);
    }


    private function validateTransitionStructure(Transition $t): void
    {
        if (!$this->model->exists) {
            throw new ApplyTransitionException(__('flowra::flowra.model_exist'));
        }

        if (!($this->isWorkflowRegisteredForModel() || $this->isWorkflowRegisteredAsSubflowModel())) {
            throw new ApplyTransitionException(
                __(
                    'flowra::flowra.workflow_not_registered_for_model',
                    ['workflow' => $this::class, 'model' => $this->model::class]
                )
            );
        }

        # check if transition is already defined in workflow #
        if (!in_array(Str::camel($t->key), array_keys($this->transitions()))) {
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

        if (isset($appliedWorkflows) && in_array($this::class, $appliedWorkflows)) {
            return true;
        }

        return false;
    }

    private function isWorkflowRegisteredAsSubflowModel(): bool
    {
        return in_array($this::class, $this->model->registeredSubflows);
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
            throw new ApplyJumpException('From state is not valid, state must not be null on jump');
        }

        return [$state, $fromStatus];
    }

    /**
     * @param  Transition  $t
     * @return void
     */
    private function __saveStatus(Transition $t): Status
    {
        [$parentId, $path] = $this->parentContextForTransition($t);

        return Status::query()->updateOrCreate(
            [
                'owner_type' => $this->model->getMorphClass(),
                'owner_id' => $this->model->getKey(),
                'workflow' => $this::class,
            ],
            [
                'parent_id' => $parentId,
                'path' => $path,
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
    }


    /**
     * @param  Transition  $t
     * @param  Status  $status
     * @return void
     */
    private function __appendToRegistry(Transition $t, Status $status): void
    {
        Registry::query()->create([
            'owner_type' => $status->owner_type ?? $this->model->getMorphClass(),
            'owner_id' => $status->owner_id ?? $this->model->getKey(),
            'workflow' => $status->workflow ?? $this::class,
            // 'parent_id' => $status->parent_id,
            'path' => $status->path,
            'transition' => $t->key,
            'from' => $t->from->value,
            'to' => $t->to->value,
//            'comment' => $t->comment,
            // 'applied_by' => $t->appliedBy,
            'type' => $t->type,
        ]);
    }

    private function parentContextForTransition(Transition $t): array
    {
        $parentId = null;
        $path = $this->model->getKey();

        $parentStatus = $this->parentFlowConfigurations['parent_current_status'] ?? null;
        $startTransition = $this->parentFlowConfigurations['start_transition'] ?? null;

        if ($parentStatus instanceof Status && $startTransition === $t->key) {
            $parentId = $parentStatus->id;
            $parentPath = trim($parentStatus->path ?? '', '/');
            $suffix = trim((string) $path, '/');
            $path = $parentPath !== '' ? $parentPath.'/'.$suffix : $suffix;
        }

        return [$parentId, $path];
    }
}
