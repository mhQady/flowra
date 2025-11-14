<?php

namespace Flowra\DTOs;

use BackedEnum;
use Flowra\Contracts\Subflow\{BindStep, DoneStep, ExitStep, StartStep, ToStep};
use LogicException;
use Str;
use UnitEnum;

final class Subflow implements BindStep, ToStep, StartStep, ExitStep, DoneStep
{
    public readonly BackedEnum|string $boundState;
    public readonly string $innerWorkflow;
    public readonly string $startTransition;
    public array $exits = [];

    public static function define(): BindStep
    {
        return new self();
    }

    public function bind(string|UnitEnum $boundState): ToStep
    {
        $this->boundState = $boundState instanceof UnitEnum
            ? ($boundState instanceof BackedEnum ? $boundState->value : $boundState->name)
            : $boundState;
        return $this;
    }

    public function to(string $innerWorkflow): StartStep
    {
        $this->innerWorkflow = $innerWorkflow;

        return $this;
    }

    public function start(string $transition): ExitStep
    {
        $this->startTransition = $transition;
        return $this;
    }

    public function exit(UnitEnum|string $exitState, string $outerTransition): DoneStep|ExitStep
    {
        $key = $exitState instanceof BackedEnum
            ? $exitState->value
            : ($exitState instanceof UnitEnum ? $exitState->name : (string) $exitState);

        $this->exits[$key] = $outerTransition;

        return $this;
    }

    public function done(): array
    {
        if (!$this->boundState || !$this->innerWorkflow || !$this->startTransition || !$this->exits) {
            throw new LogicException('Subflow is not properly configured.');
        }
        return $this->toArray();
    }

    public function toArray(): array
    {
        return [
            'bound_state' => $this->boundState,
            'workflow_class' => $this->innerWorkflow,
            'start_transition' => $this->startTransition,
            'exits' => $this->exits,

            'key' => Str::camel(class_basename($this->innerWorkflow)),
            'workflow_instance_cache' => null
        ];
    }
}
