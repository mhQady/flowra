<?php

namespace Flowra\Flows;

use Flowra\Contracts\HasFlowContract;
use Flowra\DTOs\Transition;
use Flowra\Models\History;
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


    public function current(): ?string
    {
        $row = Status::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $this->flowKey)
            ->first();

        return $row?->to;
    }

    public function history(): Collection
    {
        return History::query()
            ->where('owner_type', $this->model->getMorphClass())
            ->where('owner_id', $this->model->getKey())
            ->where('workflow', $this->flowKey)
            ->get();
    }

    /**
     * Apply a Transition atomically.
     * @throws Throwable
     */
    public function apply(Transition $t): static
    {
        // determine current (if not started, you may treat "from" as the expected initial)
        $current = $this->current();
        if ($current === null) {
            // first transition must start from its declared 'from'
            $current = $t->from;
        }
//        dd($current, $t->from->value);
        if ($current !== $t->from) {
            throw new RuntimeException("Invalid from-state: expected '{$current}', got '{$t->from->value}'.");
        }

        DB::transaction(function () use ($t) {

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
                    'comment' => $t->comment ?: null,
                    // 'applied_by' => $t->appliedBy, // uncomment if you add the column
                ]
            );

            // append to history
            History::query()->create([
                'owner_type' => $this->model->getMorphClass(),
                'owner_id' => $this->model->getKey(),
                'workflow' => $this->flowKey,
                'transition' => $t->key,
                'from' => $t->from,
                'to' => $t->to,
                'comment' => $t->comment ?: null,
                // 'applied_by' => $t->appliedBy,
            ]);
        });

        return $this;
    }

    public function __get(string $name)
    {
        if (method_exists($this, $name)) {
            return $this->{$name}();
        }

        throw new RuntimeException("Property no exists");
    }
}