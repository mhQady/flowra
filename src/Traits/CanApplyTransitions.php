<?php

namespace Flowra\Traits;

use Flowra\DTOs\Reset;
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
    /**
     * @throws Throwable
     */
    public function apply(Transition $t, ?array $comment = null): static
    {
        $this->__validateTransitionApplicable($t);

        $this->__save($t, $comment);

        return $this;
    }

    /**
     * @throws Throwable
     */
    public function jump(UnitEnum|string|int $state, string $resetName = 'reset', ?array $comment = null): static
    {
        if (!($state instanceof UnitEnum))
            $state = $this->statesClass::tryFrom($state);

        if (!($state instanceof $this->statesClass))
            throw new ApplyResetException('State is not valid, state must be of type ('.$this->statesClass::class.')');


        $resetObj = new Reset($resetName, $this->currentStatus(), $state, $this, $comment);

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

            if ($comment) {
                $t->comment = $comment;
            }

            $this->__saveStatus($t);

            $this->__appendToRegistry($t);
        });
    }

    private function __validateTransitionApplicable(Transition $t): void
    {
        // chack if model really exists in database
        if (!$this->model->exists)
            throw new ApplyTransitionException("Model that apply transition does not exist");

        // check if flow is registered for model
        if (!isset($this->model->flows) || !in_array(static::class, $this->model->flows))
            throw new ApplyTransitionException('Flow ('.$this::class.') is not registered for model ('.$this->model::class.')');

        // check if transition is already defined in flow
        if (!in_array($t, array_values($this->transitions)))
            throw new ApplyTransitionException('Transition ('.$t->key.') is not defined for flow ('.$this::class.')');

        // determine current (if not started, you may treat "from" as the expected initial)
        if (($current = $this->currentStatus()?->value ?? $t->from->value) !== $t->from->value)
            throw new ApplyTransitionException("Applying transition ({$t->key}) while current state is ({$current}) is not applicable, current state must be ({$t->from->value}).");

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
                'comment' => $t->comment,
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
            'comment' => $t->comment,
            // 'applied_by' => $t->appliedBy,
            'type' => $t->type
        ]);
    }

}