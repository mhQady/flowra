<?php

namespace Flowra\DTOs;

use Flowra\Contracts\GuardContract;
use Flowra\Enums\TransitionTypesEnum;
use Flowra\Flows\BaseWorkflow;
use Throwable;
use UnitEnum;
use Closure;

class Transition
{
    /**
     *  @param array<Closure|GuardContract|class-string<GuardContract>>
     */
    private array $guards = [];

    public function __construct(
        public readonly string $key,
        public readonly UnitEnum $from,
        public readonly UnitEnum $to,
        public readonly BaseWorkflow $workflow,
        // action: fn($flow) => event(new OwnerInfoEntered($flow->model)),
        public ?array $comment = null,
        public ?int $appliedBy = null,  // optional user id
        public int $type = TransitionTypesEnum::TRANSITION->value
    ) {

    }

    public function guard(Closure|GuardContract|string ...$guards): static
    {
        array_push($this->guards, ...$guards);

        return $this;
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
}