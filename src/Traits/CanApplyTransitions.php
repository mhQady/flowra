<?php

namespace Flowra\Traits;

use Flowra\DTOs\Jump;
use Flowra\DTOs\Transition;
use Flowra\Exceptions\ApplyResetException;
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
    public function apply(Transition $t, ?array $comment = null): static
    {
        dump('guard start evaluation 🏁');
        $this->__evaluateGuards($t);
        dump('guard ends evaluation ⚰');

        dump('validation started 🏁');
        $this->__validateTransitionApplicable($t);
        dump('validation ended ⚰');

        dump('saving started 🏁');
//        $this->__save($t, $comment);
        dump('saving ended ⚰');

        dump('running actions started 🏁');
        $this->run($t);
        dump('running actions ended ⚰');

        return $this;
    }

    /**
     * @throws Throwable
     */
    public function jumpTo(UnitEnum|string|int $state, string $resetName = 'reset', ?array $comment = null): static
    {
        [$toState, $fromState] = $this->__validateJumpApplicable($state);

        $resetObj = new Jump($resetName, $fromState, $toState, $this, $comment);

        $this->__save($resetObj);

        return $this;
    }

    /**
     * @param  Transition  $t
     * @param  array|null  $comment
     * @return void
     * @throws Throwable
     */
    private function __save(Transition $t, ?array $comment = null): void
    {
        DB::transaction(function () use ($t, $comment) {

//            if ($comment) {
//                $t->comment = $comment;
//            }

            $this->__saveStatus($t);

            $this->__appendToRegistry($t);

        });
    }

    private function __validateTransitionApplicable(Transition $t): void
    {
        # chack if model really exists in database #
        if (!$this->model->exists) {
            throw new ApplyTransitionException("Model that apply transition does not exist");
        }

        # check if workflow is registered for model #
        if (!isset($this->model->workflows) || !in_array(static::class, $this->model->workflows)) {
            throw new ApplyTransitionException('Workflow ('.$this::class.') is not registered for model ('.$this->model::class.')');
        }

        # check if transition is already defined in workflow #
        if (!in_array($t, array_values($this->transitions))) {
            throw new ApplyTransitionException('Transition <comment>('.$t->key.')</comment> is not defined for workflow <comment>('.$this::class.')</comment>');
        }

        # determine current (if not started, you may treat "from" as the expected initial) #
        if (($current = $this->currentStatus()?->value ?? $t->from->value) !== $t->from->value) {
            throw new ApplyTransitionException(
                "Applying transition <comment>({$t->key})</comment> while current state is <comment>({$current})</comment> is not applicable.
                                                \n  Model state must be <comment>({$t->from->value})</comment> so transition can be applied."
            );
        }

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
            throw new ApplyResetException('State is not valid, state must be of type ('.$this->statesClass::class.')');
        }

        if (!($fromStatus = $this->currentStatus())) {
            throw new ApplyResetException('From state is not valid, state must not be (<fg=yellow;options=bold>null</>) on jump');
        }

        return [$state, $fromStatus];
    }

    /**
     * @param  Transition  $t
     * @return void
     */
    private function __saveStatus(Transition $t): void
    {
        Status::query()->updateOrCreate(
            [
                'owner_type' => $this->model->getMorphClass(),
                'owner_id' => $this->model->getKey(),
                'workflow' => $this::class,
            ],
            [
                'transition' => $t->key,
                'from' => $t->from,
                'to' => $t->to,
//                'comment' => $t->comment,
                // 'applied_by' => $t->appliedBy,
                'type' => $t->type
            ]
        );
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
            'from' => $t->from,
            'to' => $t->to,
//            'comment' => $t->comment,
            // 'applied_by' => $t->appliedBy,
            'type' => $t->type
        ]);
    }

}
