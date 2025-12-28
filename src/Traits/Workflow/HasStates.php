<?php

namespace Flowra\Traits\Workflow;

use Flowra\DTOs\Transition;
use Flowra\Models\Status;
use Flowra\Support\WorkflowCache;
use RuntimeException;
use UnitEnum;

trait HasStates
{
    use HasStateGroups;

    private string $statesEnum;

    /**
     * @var array|Transition[]
     */
    private array $states = [];

    public Status|null $currentStatus = null;
    public ?UnitEnum $currentState = null;

    /**
     * @throws RuntimeException
     */
    public static function bootHasStates(): void
    {
        /**
         * @var UnitEnum|string $statesEnum
         */
        $statesEnum = static::class.'States';

        if (enum_exists($statesEnum)) {

            WorkflowCache::rememberIfMissing(static::class, 'statesEnum', static fn() => $statesEnum);

            WorkflowCache::rememberIfMissing(static::class, 'states', static function () use ($statesEnum) {

                $states = [];

                foreach ($statesEnum::cases() as $case) {
                    $states[$case->value] = $case;
                }

                return $states;
            });

//            static::bootStateGroups($statesEnum);
        } else {
            throw new RuntimeException('States enum not found');
        }
    }

    public function initializeHasStates(): void
    {
        $this->statesEnum = WorkflowCache::get(static::class, 'statesEnum');
        $this->states = WorkflowCache::get(static::class, 'states');

        $this->hydrateStates();
    }

    public function states(): ?array
    {
        return $this->states;
    }

    public function statesEnum(): string
    {
        return $this->statesEnum;
    }

    protected function hydrateStates(?Status $status = null): void
    {
        if (is_null($status)) {
            $status = $this->status();
        }

        $this->currentStatus = $status;
        $this->currentState = ($this->statesEnum)::tryFrom($status?->to);
    }

    public function currentStateGroup(): ?array
    {
        return static::stateGroupFor($this->currentState);
    }
}
