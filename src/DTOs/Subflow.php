<?php

namespace Flowra\DTOs;

use BackedEnum;
use Flowra\Contracts\Subflow\{BindStep, DoneStep, ExitStep, StartStep, ToStep};
use LogicException;
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

    public function make(): Subflow
    {
        if (!$this->boundState || !$this->innerWorkflow || !$this->startTransition || !$this->exits) {
            throw new LogicException('Subflow is not properly configured.');
        }
        return $this;
    }
}
