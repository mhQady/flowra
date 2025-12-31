<?php

namespace Flowra\Traits\Workflow;

use Flowra\Concretes\BaseWorkflow;
use Flowra\Contracts\HasWorkflowContract;
use Flowra\DTOs\BulkTransitionResult;
use Flowra\DTOs\Transition;
use Flowra\Exceptions\ApplyTransitionException;
use Throwable;

trait CanApplyBulkTransitions
{
    /**
     * Apply a transition to multiple models/workflows.
     *
     * @param  iterable<int, HasWorkflowContract|BaseWorkflow>  $targets
     * @param  string|Transition  $transition
     * @param  array{applied_by?: int|null, comments?: array<int, string>, continue_on_error?: bool}  $options
     * @return BulkTransitionResult
     * @throws Throwable
     */
    public function applyMany(
        iterable $targets,
        string|Transition $transition,
        array $options = []
    ): BulkTransitionResult {
        $continue = (bool) ($options['continue_on_error'] ?? false);
        $appliedBy = $options['applied_by'] ?? null;
        $comments = $options['comments'] ?? [];

        $resolved = $this->resolveBulkTransition($transition);

        $successes = [];
        $failures = [];

        foreach ($targets as $target) {
            $workflow = $this->workflowForTarget($target);

            $t = clone $resolved;
            $t->workflow($workflow);

            if ($appliedBy !== null) {
                $t->appliedBy($appliedBy);
            }

            if (!empty($comments)) {
                $t->comment(...$comments);
            }

            try {
                $workflow->evaluateGuards($t);
                $workflow->validateTransitionStructure($t);

                $status = $workflow->__save($t);
                $workflow->hydrateStates($status);
                $workflow->__executeActions($t);

                $successes[] = [
                    'target' => $target,
                    'status' => $status,
                ];
            } catch (Throwable $e) {
                $failures[] = [
                    'target' => $target,
                    'exception' => $e,
                ];

                if (!$continue) {
                    throw $e;
                }
            }
        }

        return new BulkTransitionResult($successes, $failures);
    }

    private function resolveBulkTransition(string|Transition $transition): Transition
    {
        if ($transition instanceof Transition) {
            return $transition;
        }

        $available = static::transitions();

        if (!array_key_exists($transition, $available)) {
            throw new ApplyTransitionException("Transition [$transition] not found for workflow [".static::class.'].');
        }

        return $available[$transition];
    }

    private function workflowForTarget(HasWorkflowContract|BaseWorkflow $target): BaseWorkflow
    {
        if ($target instanceof BaseWorkflow) {
            if (!($target instanceof static)) {
                throw new ApplyTransitionException('Bulk transition requires targets of the same workflow class.');
            }

            return $target;
        }

        return new static($target);
    }
}
