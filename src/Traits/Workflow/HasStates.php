<?php

namespace Flowra\Traits\Workflow;

use Flowra\Models\Status;
use UnitEnum;

trait HasStates
{
    private static array $statesClass = [];
    private static array $states = [];

    public Status|null $currentStatus = null;
    public ?UnitEnum $currentState = null;

    public static function bootHasStates(): void
    {
        $statesClass = static::class.'States';

        if (class_exists($statesClass) && enum_exists($statesClass)) {
            static::$statesClass[static::class] = $statesClass;
            static::__fillStatesProperty();
        }
    }

    public function initializeHasStates(): void
    {
        $this->hydrateStates();
    }

    public static function states(): array
    {
        static::bootIfNotBooted();

        if (!isset(static::$states[static::class]))
            return [];

        return static::$states[static::class];
    }

    public static function statesClass()
    {
        static::bootIfNotBooted();

        if (!isset(static::$statesClass[static::class]))
            return [];

        return static::$statesClass[static::class];
    }
    
    protected function hydrateStates(?Status $status = null): void
    {
        if (is_null($status)) {
            $status = $this->status();
        }

        $this->currentStatus = $status;
        $this->currentState = static::$statesClass[static::class]::tryFrom($status?->to);
    }

    private static function __fillStatesProperty(): void
    {
        foreach ((static::$statesClass[static::class])::cases() as $case) {
            static::$states[static::class][$case->value] = $case;
        }
    }
}