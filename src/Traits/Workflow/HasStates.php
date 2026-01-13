<?php

namespace Flowra\Traits\Workflow;

use Flowra\Models\Status;
use Flowra\Support\WorkflowCache;
use RuntimeException;
use UnitEnum;

trait HasStates
{
    use HasStateGroups;

    /**
     * @var string<UnitEnum>
     */
    private string $statesEnum;

    /**
     * @var array<UnitEnum>
     */
    private array $states = [];
    protected static array $cachedStates = [];
    protected static array $cachedStatesEnum = [];

    public Status|null $currentStatus = null;
    public ?UnitEnum $currentState = null;

    /**
     * @throws RuntimeException
     */
    public static function bootHasStates(): void
    {
        static::cacheStates();
    }

    public function initializeHasStates(): void
    {
        [$this->statesEnum, $this->states] = static::cacheStates();
        $this->hydrateStates();
    }

    public static function states(): array
    {
        return static::cacheStates()[1];
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
        $this->currentState = $this->statesEnum::tryFrom($status?->to);
    }

//    public function currentStateGroup(): ?array
//    {
//        return static::stateGroupFor($this->currentState);
//    }

    /**
     * Cache the states and states enum for a workflow.
     *
     * @return array{0: class-string<UnitEnum>, 1: array<UnitEnum>}
     */
    protected static function cacheStates(): array
    {
        $workflow = static::class;

        // Cache states schema & states enum if it is not already cached
        if (!isset(static::$cachedStatesEnum[$workflow], static::$cachedStates[$workflow])) {

            $statesEnum = static::resolveStatesEnum();
            $statesEnum = WorkflowCache::remember($workflow, 'statesEnum', static fn() => $statesEnum);

            $stateValues = WorkflowCache::remember($workflow, 'states',
                static fn() => array_map(static fn(UnitEnum $case) => $case->value, $statesEnum::cases())
            );
            $stateValues = is_array($stateValues) ? $stateValues : [];

            $states = [];

            foreach ($stateValues as $value) {
                $case = $statesEnum::tryFrom($value);

                if ($case !== null) {
                    $states[$case->value] = $case;
                }
            }

            static::$cachedStatesEnum[$workflow] = $statesEnum;
            static::$cachedStates[$workflow] = $states;
        }

        return [static::$cachedStatesEnum[$workflow], static::$cachedStates[$workflow]];
    }

    private static function resolveStatesEnum(): string
    {
        $statesEnum = static::class.'States';

        if (!enum_exists($statesEnum)) {
            throw new RuntimeException('States enum not found');
        }

        return $statesEnum;
    }
}
