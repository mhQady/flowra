<?php

namespace Flowra\Flows;

use Flowra\Contracts\HasFlowContract;
use Flowra\DTOs\Transition;
use Flowra\Models\Registry;
use Flowra\Models\Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnitEnum;

class BaseFlow
{
    public string $flowKey {
        set => Str::of(class_basename($value))->replace('Flow', '')->snake()->value();
    }

    public function __construct(public readonly HasFlowContract $model)
    {
        $this->flowKey = static::class;
    }

    /** helper to construct a bound transition */
    protected function t(string $key, UnitEnum $from, UnitEnum $to, array $comment = []): Transition
    {
        return new Transition(key: $key, from: $from, to: $to, flow: $this, comment: $comment);
    }

    public function status(): ?Status
    {
        return Status::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $this->flowKey)
            ->first();
    }

    public function currentStatus(): ?string
    {
        return $this->status()?->to;
    }

    public function registry(): Collection
    {
        return Registry::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $this->flowKey)
            ->get();
    }

    /**
     * Apply a Transition atomically.
     * @throws Throwable
     */
    public function apply(Transition $t, ?array $comment = null): static
    {
        $this->__validateTransitionApplicable($t);

        DB::transaction(function () use ($t, $comment) {

            if (!$comment) {
                $t->comment = $comment;
            }

            $this->__saveStatus($t);

            $this->__appendToRegistry($t);
        });

        return $this;
    }

    private function __validateTransitionApplicable(Transition $t): void
    {
        if (!$this->model->exists)
            throw new RuntimeException("Model that apply transition does not exist");

        if (!isset($this->model->flows) || !in_array(static::class, $this->model->flows))
            throw new RuntimeException('Flow ('.$this::class.') is not registered for model ('.$this->model::class.')');

        if ($t->flow::class !== static::class)
            throw new RuntimeException('Transition ('.$t->key.') is not applicable for flow ('.$this::class.')');

        // determine current (if not started, you may treat "from" as the expected initial)
        if (($current = $this->currentStatus() ?? $t->from->value) !== $t->from->value)
            throw new RuntimeException("Applying transition ({$t->key}) while current state is ({$current}) is not applicable, current state must be ({$t->from->value}).");

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
                'workflow' => $this->flowKey,
            ],
            [
                'transition' => $t->key,
                'from' => $t->from,
                'to' => $t->to,
                'comment' => $t->comment,
                // 'applied_by' => $t->appliedBy,
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
            'workflow' => $this->flowKey,
            'transition' => $t->key,
            'from' => $t->from,
            'to' => $t->to,
            'comment' => $t->comment,
            // 'applied_by' => $t->appliedBy,
        ]);
    }

    public function __get(string $name)
    {
        if (method_exists($this, $name)) {
            return $this->{$name}();
        }

        throw new RuntimeException("Property no exists");
    }
}