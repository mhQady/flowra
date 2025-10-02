<?php

namespace Flowra\DTOs;

use Closure;
use Flowra\Concretes\BaseWorkflow;
use Flowra\Contracts\ActionContract;
use Flowra\Contracts\GuardContract;
use Flowra\Enums\TransitionTypesEnum;
use Throwable;
use UnitEnum;

class Transition implements \JsonSerializable
{
    /**
     * @param $guards  array<Closure|GuardContract|class-string<GuardContract>>
     */
    private array $guards = [];
    private array $actions = [];
    private array $comments = [];

    private readonly BaseWorkflow $workflow;

    public function __construct(
        public readonly string $key,
        public readonly UnitEnum $from,
        public readonly UnitEnum $to,
        public ?int $appliedBy = null,  // optional user id
        public int $type = TransitionTypesEnum::TRANSITION->value
    ) {

    }

    public static function make(string $key, UnitEnum $from, UnitEnum $to): static
    {
        return new static($key, $from, $to);
    }

    /**
     * Attach one or many guards.
     *
     * @param  Closure|GuardContract|string  ...$guards
     * @return Transition
     */
    public function guard(Closure|GuardContract|string ...$guards): static
    {
        array_push($this->guards, ...$guards);

        return $this;
    }

    /**
     * Attach one or many actions.
     *
     * @param  Closure|ActionContract|string  ...$actions
     * @return Transition
     */
    public function action(Closure|ActionContract|string ...$actions): static
    {
        array_push($this->actions, ...$actions);

        return $this;
    }

    public function comment(string ...$comments): static
    {
        //TODO: make adding comments for flexible & more controlled

        array_push($this->comments, ...$comments);

        return $this;
    }

    public function appliedBy(?int $appliedBy = null): static
    {
        //TODO: implement appliedBy Way
        $this->appliedBy = $appliedBy;

        return $this;
    }

    public function workflow(BaseWorkflow $workflow): void
    {
        if (empty($this->workflow)) {
            $this->workflow = $workflow;
        }
    }

    /**
     * @throws Throwable
     */
    public function apply(?array $comment = null): BaseWorkflow
    {
        return $this->workflow->apply($this, $comment);
    }

    public function guards(): array
    {
        return $this->guards;
    }

    public function actions(): array
    {
        return $this->actions;
    }

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}